<?php

declare(strict_types=1);

use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Ingestion\BackfillRange;
use Sifrious\Aleph\Ingestion\BackfillRateLimit;
use Sifrious\Aleph\Ingestion\BackfillRequest;
use Sifrious\Aleph\Ingestion\CompleteBackfill;
use Sifrious\Aleph\Ingestion\ContinuationBudget;
use Sifrious\Aleph\Ingestion\IngestionPartitions;
use Sifrious\Aleph\Ingestion\IngestionQueue;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\LaunchAuthorization;
use Sifrious\Aleph\Ingestion\PartitionStatus;
use Sifrious\Aleph\Ingestion\QueuedIngestion;
use Sifrious\Aleph\Ingestion\QueueIngestion;
use Sifrious\Aleph\Ingestion\QueueReceipt;
use Sifrious\Aleph\Ingestion\StartBackfill;
use Sifrious\Aleph\Testing\Fakes\CompositeConnector;
use Sifrious\Aleph\Testing\Fakes\DiscoveryAndDownloadConnector;

final class BackfillFakeQueue implements IngestionQueue
{
    public int $dispatches = 0;

    public function dispatch(QueuedIngestion $ingestion): QueueReceipt
    {
        $this->dispatches++;

        return new QueueReceipt('backfill-job-'.$ingestion->attempt->number);
    }
}

function backfillFixture(): array
{
    $connector = new CompositeConnector('historical');
    app(ConnectorRegistry::class)->register($connector);
    $installation = app(ConnectorInstallations::class)->create($connector, 'Historical source');
    $queue = new BackfillFakeQueue;
    $command = new StartBackfill(
        app(ConnectorInstallations::class),
        app(ConnectorRegistry::class),
        app(IngestionRuns::class),
        app(IngestionPartitions::class),
        new QueueIngestion(app(IngestionRuns::class), $queue),
    );

    return [$installation, $queue, $command];
}

function backfillRequest(string $installationId, array $partitions = ['year:2025', 'year:2024']): BackfillRequest
{
    return new BackfillRequest(
        sourceInstallationId: $installationId,
        sourceReference: 'historical:account/1',
        scope: 'commits',
        range: new BackfillRange('iso8601', '2024-01-01T00:00:00Z', '2025-12-31T23:59:59Z'),
        partitions: $partitions,
        force: false,
        normalizerVersion: 'commits:2',
        rateLimit: new BackfillRateLimit(100, 60),
        budget: new ContinuationBudget(4, 10000, 900),
        idempotencyKey: 'historical-2024-2025',
        authorization: LaunchAuthorization::granted('operator:1', 'decision:backfill-1'),
    );
}

it('starts one bounded backfill with deterministic durable partitions', function (): void {
    [$installation, $queue, $command] = backfillFixture();
    $result = $command->start(backfillRequest($installation->id, ['year:2025', 'year:2024', 'year:2025']));
    $replayed = $command->start(backfillRequest($installation->id, ['year:2025', 'year:2024']));

    expect($result->run->parameters)->toBe([
        'range_format' => 'iso8601',
        'from' => '2024-01-01T00:00:00Z',
        'to' => '2025-12-31T23:59:59Z',
        'scope' => 'commits',
        'partitions' => ['year:2024', 'year:2025'],
        'force' => false,
        'normalizer_version' => 'commits:2',
        'rate_limit' => ['requests' => 100, 'per_seconds' => 60],
        'budget' => ['max_partitions' => 4, 'max_records' => 10000, 'max_runtime_seconds' => 900],
    ])
        ->and(array_map(fn ($partition) => $partition->key, $result->partitions))->toBe(['year:2024', 'year:2025'])
        ->and(array_map(fn ($partition) => $partition->position, $result->partitions))->toBe([0, 1])
        ->and($result->attempt->queueJobId)->toBe('backfill-job-1')
        ->and($replayed->run->id)->toBe($result->run->id)
        ->and($replayed->attempt->id)->toBe($result->attempt->id)
        ->and($replayed->replayed)->toBeTrue()
        ->and($queue->dispatches)->toBe(1);
});

it('pauses resumes and audits cumulative partition progress', function (): void {
    [$installation, , $command] = backfillFixture();
    $result = $command->start(backfillRequest($installation->id));
    $partitions = app(IngestionPartitions::class);
    $attempt = app(IngestionRuns::class)->startQueuedAttempt($result->attempt, 'worker:backfill');
    $first = $partitions->begin($result->partitions[0], new DateTimeImmutable('2026-08-28T15:00:00+00:00'));
    $progress = $partitions->progress(
        $first,
        ['cursor' => 'page-2'],
        100,
        98,
        2,
        new DateTimeImmutable('2026-08-28T15:01:00+00:00'),
    );
    $paused = $partitions->pause($progress, new DateTimeImmutable('2026-08-28T15:02:00+00:00'));
    $resumed = $partitions->begin($paused, new DateTimeImmutable('2026-08-28T15:03:00+00:00'));
    $finished = $partitions->complete($partitions->progress(
        $resumed,
        ['cursor' => 'page-3'],
        150,
        148,
        2,
        new DateTimeImmutable('2026-08-28T15:04:00+00:00'),
    ), new DateTimeImmutable('2026-08-28T15:05:00+00:00'));
    $second = $partitions->complete(
        $partitions->begin($result->partitions[1], new DateTimeImmutable('2026-08-28T15:00:00+00:00')),
        new DateTimeImmutable('2026-08-28T15:05:00+00:00'),
    );
    $completed = (new CompleteBackfill(app(IngestionRuns::class), $partitions))->complete($result->run, $attempt);

    expect($paused->status)->toBe(PartitionStatus::Paused)
        ->and($resumed->checkpoint)->toBe(['cursor' => 'page-2'])
        ->and($finished->status)->toBe(PartitionStatus::Completed)
        ->and($finished->processed)->toBe(150)
        ->and($finished->accepted)->toBe(148)
        ->and($finished->failed)->toBe(2)
        ->and($second->status)->toBe(PartitionStatus::Completed)
        ->and($partitions->allComplete($result->run))->toBeTrue()
        ->and($partitions->forRun($result->run))->toHaveCount(2)
        ->and($completed->status->value)->toBe('completed')
        ->and($completed->stats)->toBe(['partitions' => 2, 'processed' => 150, 'accepted' => 148, 'failed' => 2]);
});

it('refuses unsupported connectors and partition plans beyond the budget', function (): void {
    $connector = new DiscoveryAndDownloadConnector;
    app(ConnectorRegistry::class)->register($connector);
    $installation = app(ConnectorInstallations::class)->create($connector, 'No backfill');
    $queue = new BackfillFakeQueue;
    $command = new StartBackfill(
        app(ConnectorInstallations::class),
        app(ConnectorRegistry::class),
        app(IngestionRuns::class),
        app(IngestionPartitions::class),
        new QueueIngestion(app(IngestionRuns::class), $queue),
    );

    expect(fn () => $command->start(backfillRequest($installation->id)))
        ->toThrow(InvalidArgumentException::class, 'does not advertise');

    [$supported, , $supportedCommand] = backfillFixture();
    $request = backfillRequest($supported->id, ['a', 'b', 'c', 'd', 'e']);

    expect(fn () => $supportedCommand->start($request))
        ->toThrow(InvalidArgumentException::class, 'fit the declared work budget');
});
