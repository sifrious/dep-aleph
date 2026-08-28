<?php

declare(strict_types=1);

use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\Contracts\SyncsIncrementally;
use Sifrious\Aleph\Connector\Values\OperationRequest;
use Sifrious\Aleph\Connector\Values\OperationResult;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;
use Sifrious\Aleph\Ingestion\ChangeKind;
use Sifrious\Aleph\Ingestion\CheckpointRule;
use Sifrious\Aleph\Ingestion\CheckpointValue;
use Sifrious\Aleph\Ingestion\CompleteIncrementalSync;
use Sifrious\Aleph\Ingestion\ContinuationBudget;
use Sifrious\Aleph\Ingestion\IncrementalChangeDraft;
use Sifrious\Aleph\Ingestion\IncrementalChanges;
use Sifrious\Aleph\Ingestion\IncrementalSyncRequest;
use Sifrious\Aleph\Ingestion\IngestionCheckpoints;
use Sifrious\Aleph\Ingestion\IngestionQueue;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\LaunchAuthorization;
use Sifrious\Aleph\Ingestion\QueuedIngestion;
use Sifrious\Aleph\Ingestion\QueueIngestion;
use Sifrious\Aleph\Ingestion\QueueReceipt;
use Sifrious\Aleph\Ingestion\SourceStreams;
use Sifrious\Aleph\Ingestion\StartIncrementalSync;
use Sifrious\Aleph\Ingestion\SyncStrategy;
use Sifrious\Aleph\Testing\Fakes\BaseFakeConnector;

final class IncrementalFakeConnector extends BaseFakeConnector implements SyncsIncrementally
{
    public function syncIncrementally(OperationRequest $request): OperationResult
    {
        return OperationResult::completed();
    }
}

final class IncrementalFakeQueue implements IngestionQueue
{
    public function dispatch(QueuedIngestion $ingestion): QueueReceipt
    {
        return new QueueReceipt('incremental-job-'.$ingestion->attempt->number);
    }
}

function incrementalFixture(): array
{
    $connector = new IncrementalFakeConnector('incremental');
    app(ConnectorRegistry::class)->register($connector);
    $installation = app(ConnectorInstallations::class)->create($connector, 'Incremental source');
    $stream = app(SourceStreams::class)->create(
        $installation->id,
        'repository:R1',
        'repository',
        'R1',
        SyncStrategy::HighWater,
    );
    $command = new StartIncrementalSync(
        app(ConnectorInstallations::class),
        app(ConnectorRegistry::class),
        app(SourceStreams::class),
        app(IngestionCheckpoints::class),
        app(IngestionRuns::class),
        new QueueIngestion(app(IngestionRuns::class), new IncrementalFakeQueue),
    );

    return [$stream, $command];
}

function incrementalRequest(string $streamId, string $key): IncrementalSyncRequest
{
    return new IncrementalSyncRequest(
        sourceStreamId: $streamId,
        sourceReference: 'github:repository/R1',
        partitionKey: 'commits',
        fullReconciliation: false,
        budget: new ContinuationBudget(1, 1000, 120),
        idempotencyKey: $key,
        authorization: LaunchAuthorization::granted('operator:1', 'decision:incremental'),
    );
}

function acceptIncrementalChange(string $runId, string $resource, string $payload): string
{
    $accepted = app(EnvelopeSubmitter::class)->submit(new ObservationEnvelope(
        sourceReference: 'github:repository/R1',
        sourceName: 'Repository R1',
        resourceReference: $resource,
        observedAt: new DateTimeImmutable('2026-08-28T15:00:00+00:00'),
        payload: $payload,
        provenance: new Provenance(
            'incremental',
            '1.0.0',
            'source-installation:R1',
            new DateTimeImmutable('2026-08-28T15:00:00+00:00'),
            $runId,
        ),
    ));

    return (string) $accepted->acceptedId();
}

it('accepts edits and deletions exactly once then advances the committed high water mark', function (): void {
    [$stream, $command] = incrementalFixture();
    $started = $command->start(incrementalRequest($stream->id, 'window-1'));
    $runs = app(IngestionRuns::class);
    $attempt = $runs->startQueuedAttempt($started->attempt, 'worker:incremental');
    $updatedReference = acceptIncrementalChange($started->run->id, 'github:issue/1', 'updated');
    $deletedReference = acceptIncrementalChange($started->run->id, 'github:issue/2', 'deleted');
    $runs->recordProgress(
        $started->run,
        $attempt,
        [],
        ['observed' => 2],
        [$updatedReference, $deletedReference],
    );
    $changes = app(IncrementalChanges::class);
    $updatedDraft = new IncrementalChangeDraft(
        'event:101',
        ChangeKind::Updated,
        'github:issue/1',
        hash('sha256', 'updated'),
        $updatedReference,
        new DateTimeImmutable('2026-08-28T14:59:00+00:00'),
    );
    $deletedDraft = new IncrementalChangeDraft(
        'event:102',
        ChangeKind::Deleted,
        'github:issue/2',
        hash('sha256', 'deleted'),
        $deletedReference,
        new DateTimeImmutable('2026-08-28T14:59:30+00:00'),
    );
    $updated = $changes->record($stream, $started->run, $attempt, 'commits', $updatedDraft, new DateTimeImmutable);
    $replayedUpdate = $changes->record($stream, $started->run, $attempt, 'commits', $updatedDraft, new DateTimeImmutable);
    $deleted = $changes->record($stream, $started->run, $attempt, 'commits', $deletedDraft, new DateTimeImmutable);
    $completion = (new CompleteIncrementalSync($runs, app(IngestionCheckpoints::class), $changes))->complete(
        $stream,
        $started->run,
        $attempt,
        'commits',
        new CheckpointValue('github.commit', '1', 'sha-200', CheckpointRule::Monotonic, 200),
        0,
    );

    expect($started->run->parameters['strategy'])->toBe('high_water')
        ->and($updated?->id)->toBe($replayedUpdate?->id)
        ->and($deleted?->kind)->toBe(ChangeKind::Deleted)
        ->and($changes->forRun($started->run))->toHaveCount(2)
        ->and($completion->acceptedChanges)->toBe(2)
        ->and($completion->checkpoint?->version)->toBe(1)
        ->and($completion->run->stats)->toBe([
            'accepted_changes' => 2,
            'added' => 0,
            'updated' => 1,
            'deleted' => 1,
        ]);
});

it('accepts zero changes for an unchanged source without advancing the checkpoint', function (): void {
    [$stream, $command] = incrementalFixture();
    $first = $command->start(incrementalRequest($stream->id, 'window-1'));
    $runs = app(IngestionRuns::class);
    $firstAttempt = $runs->startQueuedAttempt($first->attempt, 'worker:first');
    $reference = acceptIncrementalChange($first->run->id, 'github:issue/1', 'initial');
    $runs->recordProgress($first->run, $firstAttempt, [], ['observed' => 1], [$reference]);
    $changes = app(IncrementalChanges::class);
    $changes->record($stream, $first->run, $firstAttempt, 'commits', new IncrementalChangeDraft(
        'event:1',
        ChangeKind::Added,
        'github:issue/1',
        hash('sha256', 'initial'),
        $reference,
        new DateTimeImmutable('2026-08-28T14:00:00+00:00'),
    ), new DateTimeImmutable);
    $checkpointValue = new CheckpointValue('github.commit', '1', 'sha-100', CheckpointRule::Monotonic, 100);
    (new CompleteIncrementalSync($runs, app(IngestionCheckpoints::class), $changes))->complete(
        $stream,
        $first->run,
        $firstAttempt,
        'commits',
        $checkpointValue,
        0,
    );
    $second = $command->start(incrementalRequest($stream->id, 'window-2'));
    $secondAttempt = $runs->startQueuedAttempt($second->attempt, 'worker:second');
    $unchanged = $changes->record($stream, $second->run, $secondAttempt, 'commits', new IncrementalChangeDraft(
        'poll:unchanged',
        ChangeKind::Unchanged,
        'github:repository/R1',
        hash('sha256', 'sha-100'),
        null,
        new DateTimeImmutable('2026-08-28T15:00:00+00:00'),
    ), new DateTimeImmutable);
    $completion = (new CompleteIncrementalSync($runs, app(IngestionCheckpoints::class), $changes))->complete(
        $stream,
        $second->run,
        $secondAttempt,
        'commits',
        $checkpointValue,
        1,
    );

    expect($second->checkpoint?->version)->toBe(1)
        ->and($second->run->parameters['checkpoint']['value'])->toBe('sha-100')
        ->and($unchanged)->toBeNull()
        ->and($changes->forRun($second->run))->toBe([])
        ->and($completion->acceptedChanges)->toBe(0)
        ->and($completion->checkpoint?->version)->toBe(1)
        ->and($completion->run->stats['accepted_changes'])->toBe(0)
        ->and($stream->syncStrategy->requiresPeriodicReconciliation())->toBeFalse()
        ->and(SyncStrategy::Hash->requiresPeriodicReconciliation())->toBeTrue();
});
