<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Watch;

use InvalidArgumentException;
use Sifrious\Aleph\Ingestion\ContinuationBudget;
use Sifrious\Aleph\Ingestion\IncrementalSyncRequest;
use Sifrious\Aleph\Ingestion\IngestionTrigger;
use Sifrious\Aleph\Ingestion\LaunchAuthorization;
use Sifrious\Aleph\Ingestion\SourceStreams;
use Sifrious\Aleph\Ingestion\StartIncrementalSync;
use Sifrious\Aleph\Ingestion\SyncStrategy;
use Throwable;

final readonly class TriggerRepositoryWatch
{
    public function __construct(
        private RepositoryWatches $watches,
        private SourceStreams $streams,
        private StartIncrementalSync $incremental,
    ) {}

    public function trigger(
        string $watchId,
        RepositoryWatchSignal $signal,
        ?ContinuationBudget $budget = null,
    ): RepositoryWatchLaunch {
        $watch = $this->watches->find($watchId)
            ?? throw new InvalidArgumentException("Repository watch [{$watchId}] does not exist.");

        if (! $watch->enabled) {
            throw new InvalidArgumentException('A disabled repository watch cannot launch ingestion.');
        }

        if ($watch->backoffUntil !== null && $watch->backoffUntil > $signal->observedAt) {
            throw new InvalidArgumentException('A repository watch cannot launch ingestion during backoff.');
        }

        $this->assertRefAllowed($watch, $signal->ref);

        if ($watch->headReference === $signal->headReference) {
            return new RepositoryWatchLaunch($watch, $signal, null, true);
        }

        $stream = $this->streams->findByKey($watch->sourceInstallationId, $watch->repositoryReference)
            ?? $this->streams->create(
                $watch->sourceInstallationId,
                $watch->repositoryReference,
                'repository',
                $watch->repositoryReference,
                $this->strategy($watch->mode),
            );
        $triggerKey = $signal->triggerKey($watch);
        $budget ??= new ContinuationBudget(1, 1000, 120);

        try {
            $ingestion = $this->incremental->start(new IncrementalSyncRequest(
                sourceStreamId: $stream->id,
                sourceReference: $watch->sourceReference,
                partitionKey: $signal->ref,
                fullReconciliation: $signal->fullReconciliation(),
                budget: $budget,
                idempotencyKey: $triggerKey,
                authorization: LaunchAuthorization::granted(
                    'system:repository-watch',
                    'repository-watch:'.$watch->id.'/'.$triggerKey,
                ),
                trigger: $signal->origin === RepositoryWatchSignalOrigin::Webhook
                    ? IngestionTrigger::Webhook
                    : IngestionTrigger::Scheduled,
            ));
            $claimed = $this->watches->claimTrigger($watch, $triggerKey, $signal->observedAt, $ingestion->run->id);
        } catch (Throwable $failure) {
            $this->watches->recordFailure($watch, $failure->getMessage(), $signal->observedAt);

            throw $failure;
        }

        return new RepositoryWatchLaunch($watch, $signal, $ingestion, ! $claimed || $ingestion->replayed);
    }

    private function strategy(RepositoryWatchMode $mode): SyncStrategy
    {
        return match ($mode) {
            RepositoryWatchMode::Poll => SyncStrategy::HighWater,
            RepositoryWatchMode::Webhook, RepositoryWatchMode::Hybrid => SyncStrategy::Webhook,
        };
    }

    private function assertRefAllowed(RepositoryWatch $watch, string $ref): void
    {
        $refs = $watch->filters['refs'] ?? null;

        if (is_array($refs) && $refs !== [] && ! in_array($ref, array_map(strval(...), $refs), true)) {
            throw new InvalidArgumentException("Repository ref [{$ref}] is outside this watch policy.");
        }
    }
}
