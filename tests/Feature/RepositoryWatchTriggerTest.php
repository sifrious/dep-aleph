<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\Contracts\SyncsIncrementally;
use Sifrious\Aleph\Connector\Values\OperationRequest;
use Sifrious\Aleph\Connector\Values\OperationResult;
use Sifrious\Aleph\Connector\Watch\LandingRepoWatchAdapter;
use Sifrious\Aleph\Connector\Watch\RepositoryWatches;
use Sifrious\Aleph\Connector\Watch\RepositoryWatchMode;
use Sifrious\Aleph\Connector\Watch\RepositoryWatchSignal;
use Sifrious\Aleph\Connector\Watch\RepositoryWatchSignalOrigin;
use Sifrious\Aleph\Connector\Watch\TriggerRepositoryWatch;
use Sifrious\Aleph\Ingestion\Capability;
use Sifrious\Aleph\Ingestion\IngestionCheckpoints;
use Sifrious\Aleph\Ingestion\IngestionQueue;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\IngestionTrigger;
use Sifrious\Aleph\Ingestion\QueuedIngestion;
use Sifrious\Aleph\Ingestion\QueueIngestion;
use Sifrious\Aleph\Ingestion\QueueReceipt;
use Sifrious\Aleph\Ingestion\SourceStreams;
use Sifrious\Aleph\Ingestion\StartIncrementalSync;
use Sifrious\Aleph\Ingestion\SyncStrategy;
use Sifrious\Aleph\Testing\Fakes\BaseFakeConnector;

final class WatchIncrementalConnector extends BaseFakeConnector implements SyncsIncrementally
{
    public function syncIncrementally(OperationRequest $request): OperationResult
    {
        return OperationResult::completed();
    }
}

final class WatchIngestionQueue implements IngestionQueue
{
    public int $dispatches = 0;

    public bool $fail = false;

    public function dispatch(QueuedIngestion $ingestion): QueueReceipt
    {
        $this->dispatches++;

        if ($this->fail) {
            throw new RuntimeException('The repository queue is unavailable.');
        }

        return new QueueReceipt('watch-job-'.$ingestion->attempt->id);
    }
}

function watchTriggerFixture(
    RepositoryWatchMode $mode = RepositoryWatchMode::Hybrid,
    bool $enabled = true,
    ?string $backoffUntil = null,
    int $legacyId = 42,
): array {
    $connector = new WatchIncrementalConnector('watch-incremental');
    app(ConnectorRegistry::class)->register($connector);
    $installation = app(ConnectorInstallations::class)->create(
        $connector,
        'GitHub Acme '.$legacyId,
        externalAccountId: 'github:account/acme-'.$legacyId,
        funesSourceAccountId: 'source-account:github-acme-'.$legacyId,
    );
    $legacy = (new LandingRepoWatchAdapter)->adapt([
        'id' => $legacyId,
        'cadence_minutes' => 5,
        'last_indexed_ref' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'enabled' => $enabled,
        'backoff_until' => $backoffUntil,
        'next_sync_at' => '2026-08-28T11:00:00Z',
        'created_at' => '2026-08-01T10:00:00Z',
        'updated_at' => '2026-08-28T10:00:00Z',
    ], $installation->id, 'github:source:acme', 'git:repository/acme/widget', $mode, [
        'refs' => ['refs/heads/main'],
    ]);
    $watch = app(RepositoryWatches::class)->import($legacy);
    $queue = new WatchIngestionQueue;
    $incremental = new StartIncrementalSync(
        app(ConnectorInstallations::class),
        app(ConnectorRegistry::class),
        app(SourceStreams::class),
        app(IngestionCheckpoints::class),
        app(IngestionRuns::class),
        new QueueIngestion(app(IngestionRuns::class), $queue),
    );

    return [$watch, new TriggerRepositoryWatch(app(RepositoryWatches::class), app(SourceStreams::class), $incremental), $queue];
}

function watchSignal(RepositoryWatchSignalOrigin $origin, string $head = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'): RepositoryWatchSignal
{
    return new RepositoryWatchSignal(
        $origin,
        'refs/heads/main',
        $head,
        new DateTimeImmutable('2026-08-28T11:00:00Z'),
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    );
}

it('launches webhook-only and polling-only changes with their correct reconciliation semantics', function (): void {
    [$webhookWatch, $webhookTrigger] = watchTriggerFixture(RepositoryWatchMode::Webhook);
    $webhook = $webhookTrigger->trigger($webhookWatch->id, watchSignal(RepositoryWatchSignalOrigin::Webhook));
    $webhookStream = app(SourceStreams::class)->find($webhook->ingestion?->stream->id ?? '');

    expect($webhook->launched())->toBeTrue()
        ->and($webhook->ingestion?->run->trigger)->toBe(IngestionTrigger::Webhook)
        ->and($webhook->ingestion?->run->parameters['full_reconciliation'])->toBeFalse()
        ->and($webhookStream?->syncStrategy)->toBe(SyncStrategy::Webhook);

    [$pollWatch, $pollTrigger] = watchTriggerFixture(RepositoryWatchMode::Poll, legacyId: 43);
    $poll = $pollTrigger->trigger($pollWatch->id, watchSignal(RepositoryWatchSignalOrigin::Poll));
    $pollStream = app(SourceStreams::class)->find($poll->ingestion?->stream->id ?? '');

    expect($poll->launched())->toBeTrue()
        ->and($poll->ingestion?->run->trigger)->toBe(IngestionTrigger::Scheduled)
        ->and($poll->ingestion?->run->parameters['full_reconciliation'])->toBeTrue()
        ->and($pollStream?->syncStrategy)->toBe(SyncStrategy::HighWater);
});

it('coalesces webhook polling and debounce overlap beneath one idempotent run', function (): void {
    [$watch, $trigger, $queue] = watchTriggerFixture();
    $webhook = $trigger->trigger($watch->id, watchSignal(RepositoryWatchSignalOrigin::Webhook));
    $poll = $trigger->trigger($watch->id, watchSignal(RepositoryWatchSignalOrigin::Poll));
    $debounced = $trigger->trigger($watch->id, watchSignal(RepositoryWatchSignalOrigin::Webhook));

    expect($webhook->launched())->toBeTrue()
        ->and($poll->coalesced)->toBeTrue()
        ->and($debounced->coalesced)->toBeTrue()
        ->and($poll->ingestion?->run->id)->toBe($webhook->ingestion?->run->id)
        ->and($debounced->ingestion?->attempt->id)->toBe($webhook->ingestion?->attempt->id)
        ->and($queue->dispatches)->toBe(1)
        ->and(DB::table('aleph_repository_watch_triggers')->count())->toBe(1);
});

it('repairs a missed webhook by reconciliation polling and launches a force-pushed head', function (): void {
    [$watch, $trigger] = watchTriggerFixture();
    $missedWebhookRepair = $trigger->trigger($watch->id, watchSignal(RepositoryWatchSignalOrigin::Reconciliation));
    $forcePush = $trigger->trigger($watch->id, new RepositoryWatchSignal(
        RepositoryWatchSignalOrigin::Poll,
        'refs/heads/main',
        'cccccccccccccccccccccccccccccccccccccccc',
        new DateTimeImmutable('2026-08-28T11:05:00Z'),
        'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        true,
    ));

    expect($missedWebhookRepair->launched())->toBeTrue()
        ->and($missedWebhookRepair->ingestion?->run->parameters['full_reconciliation'])->toBeTrue()
        ->and($forcePush->launched())->toBeTrue()
        ->and($forcePush->signal->forcePushed)->toBeTrue()
        ->and($forcePush->ingestion?->run->id)->not->toBe($missedWebhookRepair->ingestion?->run->id);
});

it('rejects unchanged heads disabled watches backoff and refs outside policy', function (): void {
    [$watch, $trigger] = watchTriggerFixture();
    $unchanged = $trigger->trigger($watch->id, watchSignal(
        RepositoryWatchSignalOrigin::Poll,
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    ));

    expect($unchanged->coalesced)->toBeTrue()
        ->and($unchanged->ingestion)->toBeNull();

    [$disabled, $disabledTrigger] = watchTriggerFixture(enabled: false, legacyId: 43);
    expect(fn () => $disabledTrigger->trigger($disabled->id, watchSignal(RepositoryWatchSignalOrigin::Webhook)))
        ->toThrow(InvalidArgumentException::class, 'disabled');

    [$backoff, $backoffTrigger] = watchTriggerFixture(backoffUntil: '2026-08-28T12:00:00Z', legacyId: 44);
    expect(fn () => $backoffTrigger->trigger($backoff->id, watchSignal(RepositoryWatchSignalOrigin::Poll)))
        ->toThrow(InvalidArgumentException::class, 'backoff');

    expect(fn () => $trigger->trigger($watch->id, new RepositoryWatchSignal(
        RepositoryWatchSignalOrigin::Poll,
        'refs/heads/feature',
        'dddddddddddddddddddddddddddddddddddddddd',
        new DateTimeImmutable('2026-08-28T11:00:00Z'),
    )))->toThrow(InvalidArgumentException::class, 'outside');
});

it('retains retryable watch failure state when checkpointed work cannot be queued', function (): void {
    [$watch, $trigger, $queue] = watchTriggerFixture();
    $queue->fail = true;

    expect(fn () => $trigger->trigger($watch->id, watchSignal(RepositoryWatchSignalOrigin::Poll)))
        ->toThrow(RuntimeException::class, 'queue is unavailable');

    $failed = app(RepositoryWatches::class)->find($watch->id);

    $run = app(IngestionRuns::class)->latest('github:source:acme', Capability::IncrementalSync);

    expect($failed?->lastError)->toBe('The repository queue is unavailable.')
        ->and($failed?->backoffUntil?->format(DATE_ATOM))->toBe('2026-08-28T11:10:00+00:00')
        ->and(DB::table('aleph_ingestion_runs')->count())->toBe(1)
        ->and($run)->not->toBeNull()
        ->and(app(IngestionRuns::class)->attempts($run))->toHaveCount(1);
});
