<?php

declare(strict_types=1);

use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;
use Sifrious\Aleph\Ingestion\Capability;
use Sifrious\Aleph\Ingestion\CheckpointValue;
use Sifrious\Aleph\Ingestion\FreshnessExpectation;
use Sifrious\Aleph\Ingestion\FreshnessStatus;
use Sifrious\Aleph\Ingestion\IngestionCheckpoints;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\RunFailure;
use Sifrious\Aleph\Ingestion\SourceStreams;
use Sifrious\Aleph\Ingestion\SourceStreamStatuses;
use Sifrious\Aleph\Testing\Fakes\DiscoveryAndDownloadConnector;

function freshnessFixture(): array
{
    $connector = new DiscoveryAndDownloadConnector;
    app(ConnectorRegistry::class)->register($connector);
    $installation = app(ConnectorInstallations::class)->create($connector, 'Freshness source');
    $streams = app(SourceStreams::class);
    $synchronized = $streams->create($installation->id, 'channel:C1', 'slack_channel', 'C1');
    $never = $streams->create($installation->id, 'channel:C2', 'slack_channel', 'C2');
    $runs = app(IngestionRuns::class);
    $run = $runs->start(
        'slack:channel/C1',
        Capability::IncrementalSync,
        [],
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
    $runs->recordProgress($run, $attempt, [], ['accepted' => 1], [$reference]);
    $checkpoint = app(IngestionCheckpoints::class)->commit(
        $synchronized,
        Capability::IncrementalSync,
        'history',
        new CheckpointValue('slack.cursor', '1', 'page-1'),
        0,
        $run,
        [$reference],
        $attempt,
    );
    $runs->succeedAttempt($run, $attempt, ['accepted' => 1], [$reference]);

    return [
        $installation,
        $synchronized,
        $never,
        $runs->find($run->id),
        $runs->attempt($attempt->id),
        $checkpoint,
    ];
}

it('projects last success and derives healthy due and stale at query time', function (): void {
    [, $stream, , $run, $attempt, $checkpoint] = freshnessFixture();
    $statuses = app(SourceStreamStatuses::class);
    $expectation = new FreshnessExpectation(60, 180);
    $projected = $statuses->project($stream, $run, $attempt, $expectation, $run->finishedAt);
    $healthy = $statuses->find($stream, $run->finishedAt->modify('+30 seconds'));
    $due = $statuses->find($stream, $run->finishedAt->modify('+90 seconds'));
    $stale = $statuses->find($stream, $run->finishedAt->modify('+181 seconds'));

    expect($projected->lastAttemptId)->toBe($attempt->id)
        ->and($projected->lastSuccessfulRunId)->toBe($run->id)
        ->and($projected->acceptedThroughAt?->format(DATE_ATOM))->toBe($checkpoint->committedAt->format(DATE_ATOM))
        ->and($projected->nextDueAt?->format(DATE_ATOM))->toBe($run->finishedAt->modify('+60 seconds')->format(DATE_ATOM))
        ->and($healthy->freshness)->toBe(FreshnessStatus::Healthy)
        ->and($due->freshness)->toBe(FreshnessStatus::Due)
        ->and($stale->freshness)->toBe(FreshnessStatus::Stale)
        ->and($stale->toArray()['has_synchronized'])->toBeTrue();
});

it('returns an explicit never synchronized row for every unprojected installed stream', function (): void {
    [$installation, $synchronized, $never] = freshnessFixture();
    $statuses = app(SourceStreamStatuses::class);
    $now = new DateTimeImmutable('2026-08-28T15:00:00+00:00');
    $statuses->configure($synchronized, new FreshnessExpectation(60, 180), $now);
    $all = $statuses->allForInstallation($installation->id, $now);

    expect($all)->toHaveCount(2)
        ->and(array_map(fn ($status) => $status->sourceStreamId, $all))->toBe([$synchronized->id, $never->id])
        ->and($all[1]->freshness)->toBe(FreshnessStatus::NeverSynchronized)
        ->and($all[1]->lastSuccessAt)->toBeNull()
        ->and($all[1]->toArray()['has_synchronized'])->toBeFalse();
});

it('updates last attempt after failure without erasing the prior success', function (): void {
    [$installation, $stream, , $successfulRun, $successfulAttempt] = freshnessFixture();
    $statuses = app(SourceStreamStatuses::class);
    $expectation = new FreshnessExpectation(60, 180);
    $statuses->project($stream, $successfulRun, $successfulAttempt, $expectation, $successfulRun->finishedAt);
    $runs = app(IngestionRuns::class);
    $failedRun = $runs->start(
        'slack:channel/C1',
        Capability::IncrementalSync,
        [],
        connectorId: 'slack',
        sourceInstallationId: $installation->id,
    );
    $failedAttempt = $runs->beginAttempt($failedRun);
    $runs->failAttempt($failedRun, $failedAttempt, new RunFailure('rate_limited', 'Try later.', true));
    $projected = $statuses->project(
        $stream,
        $runs->find($failedRun->id),
        $runs->attempt($failedAttempt->id),
        $expectation,
        new DateTimeImmutable,
    );

    expect($projected->lastAttemptId)->toBe($failedAttempt->id)
        ->and($projected->lastSuccessfulRunId)->toBe($successfulRun->id)
        ->and($projected->lastSuccessAt?->format(DATE_ATOM))->toBe($successfulRun->finishedAt?->format(DATE_ATOM));
});
