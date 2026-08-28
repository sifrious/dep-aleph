<?php

declare(strict_types=1);

use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;
use Sifrious\Aleph\Ingestion\Capability;
use Sifrious\Aleph\Ingestion\CheckpointRule;
use Sifrious\Aleph\Ingestion\CheckpointValue;
use Sifrious\Aleph\Ingestion\ContinuationBudget;
use Sifrious\Aleph\Ingestion\ContinuationLeases;
use Sifrious\Aleph\Ingestion\IngestionCheckpoints;
use Sifrious\Aleph\Ingestion\IngestionQueue;
use Sifrious\Aleph\Ingestion\IngestionRunQueries;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\QueuedIngestion;
use Sifrious\Aleph\Ingestion\QueueIngestion;
use Sifrious\Aleph\Ingestion\QueueReceipt;
use Sifrious\Aleph\Ingestion\ResumeIngestion;
use Sifrious\Aleph\Ingestion\ResumeIngestionRequest;
use Sifrious\Aleph\Ingestion\ResumeRejected;
use Sifrious\Aleph\Ingestion\SourceStreams;
use Sifrious\Aleph\Testing\Fakes\DiscoveryAndDownloadConnector;

final class ResumeFakeQueue implements IngestionQueue
{
    public int $dispatches = 0;

    public function dispatch(QueuedIngestion $ingestion): QueueReceipt
    {
        $this->dispatches++;

        return new QueueReceipt('resume-job-'.$ingestion->attempt->number);
    }
}

function resumeFixture(): array
{
    $connector = new DiscoveryAndDownloadConnector;
    app(ConnectorRegistry::class)->register($connector);
    $installation = app(ConnectorInstallations::class)->create($connector, 'Resume source');
    $stream = app(SourceStreams::class)->create($installation->id, 'channel:C1', 'slack_channel', 'C1');
    $runs = app(IngestionRuns::class);
    $run = $runs->start(
        'slack:channel/C1',
        Capability::Backfill,
        ['oldest' => '1700000000'],
        connectorId: 'slack',
        sourceInstallationId: $installation->id,
    );
    $attempt = $runs->beginAttempt($run);
    $accepted = app(EnvelopeSubmitter::class)->submit(new ObservationEnvelope(
        sourceReference: $run->sourceReference,
        sourceName: 'Slack channel C1',
        resourceReference: 'slack:message/1',
        observedAt: new DateTimeImmutable('2026-08-28T10:00:00+00:00'),
        payload: 'message-1',
        provenance: new Provenance(
            'slack',
            '1.0.0',
            $installation->id,
            new DateTimeImmutable('2026-08-28T10:00:00+00:00'),
            $run->id,
        ),
    ));
    $reference = (string) $accepted->acceptedId();
    $runs->recordProgress($run, $attempt, ['worker_cursor' => 'uncommitted-page'], ['accepted' => 1], [$reference]);
    app(IngestionCheckpoints::class)->commit(
        $stream,
        Capability::Backfill,
        'history',
        new CheckpointValue('slack.cursor', '1', 'accepted-page-1', CheckpointRule::Monotonic, 100),
        0,
        $run,
        [$reference],
        $attempt,
    );
    $runs->interrupt($runs->find($run->id), 'Worker stopped.');

    return [$runs->find($run->id), $stream, $attempt, $reference];
}

it('resumes from the last accepted checkpoint with a bounded leased continuation', function (): void {
    [$run, $stream, $firstAttempt, $accepted] = resumeFixture();
    $queue = new ResumeFakeQueue;
    $resume = new ResumeIngestion(
        app(IngestionRuns::class),
        app(SourceStreams::class),
        app(IngestionCheckpoints::class),
        app(ContinuationLeases::class),
        new QueueIngestion(app(IngestionRuns::class), $queue),
    );
    $request = new ResumeIngestionRequest(
        $run->id,
        $stream->id,
        ['history'],
        'worker:resume-1',
        new ContinuationBudget(1, 250, 30),
        60,
        new DateTimeImmutable('2026-08-28T15:00:00+00:00'),
    );
    $result = $resume->resume($request);
    $replayed = $resume->resume($request);
    $timeline = app(IngestionRunQueries::class)->find($run->id);

    expect($result->attempt->number)->toBe(2)
        ->and($result->attempt->checkpoint['continuations'][0]['value'])->toBe('accepted-page-1')
        ->and($result->attempt->checkpoint['continuations'][0]['accepted_references'])->toBe([$accepted])
        ->and($result->attempt->checkpoint['budget'])->toBe([
            'max_partitions' => 1,
            'max_records' => 250,
            'max_runtime_seconds' => 30,
        ])
        ->and($result->attempt->queueJobId)->toBe('resume-job-2')
        ->and($replayed->attempt->id)->toBe($result->attempt->id)
        ->and($replayed->leases[0]->id)->toBe($result->leases[0]->id)
        ->and($queue->dispatches)->toBe(1)
        ->and($timeline?->attempts[0]->status->value)->toBe('interrupted')
        ->and($timeline?->attempts)->toHaveCount(2)
        ->and($firstAttempt->checkpoint)->toBe([]);
});

it('refuses unbounded partitions and an active lease held by another worker', function (): void {
    [$run, $stream] = resumeFixture();
    $queue = new ResumeFakeQueue;
    $resume = new ResumeIngestion(
        app(IngestionRuns::class),
        app(SourceStreams::class),
        app(IngestionCheckpoints::class),
        app(ContinuationLeases::class),
        new QueueIngestion(app(IngestionRuns::class), $queue),
    );
    $at = new DateTimeImmutable('2026-08-28T15:00:00+00:00');
    $resume->resume(new ResumeIngestionRequest(
        $run->id,
        $stream->id,
        ['history'],
        'worker:one',
        new ContinuationBudget(1, 100, 30),
        60,
        $at,
    ));

    expect(fn () => $resume->resume(new ResumeIngestionRequest(
        $run->id,
        $stream->id,
        ['history', 'threads'],
        'worker:two',
        new ContinuationBudget(1, 100, 30),
        60,
        $at,
    )))->toThrow(ResumeRejected::class, 'fit the continuation budget')
        ->and(fn () => app(ContinuationLeases::class)->acquire(
            $stream,
            Capability::Backfill,
            'history',
            $run,
            'worker:two',
            $at,
            60,
        ))->toThrow(ResumeRejected::class, 'already leased')
        ->and(fn () => $resume->resume(new ResumeIngestionRequest(
            $run->id,
            $stream->id,
            ['history'],
            'worker:two',
            new ContinuationBudget(1, 100, 30),
            60,
            $at,
        )))->toThrow(ResumeRejected::class, 'another lease owner');
});
