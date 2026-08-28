<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use InvalidArgumentException;

final readonly class CompleteIncrementalSync
{
    public function __construct(
        private IngestionRuns $runs,
        private IngestionCheckpoints $checkpoints,
        private IncrementalChanges $changes,
    ) {}

    public function complete(
        SourceStream $stream,
        IngestionRun $run,
        IngestionAttempt $attempt,
        string $partitionKey,
        CheckpointValue $nextCheckpoint,
        int $expectedVersion,
    ): IncrementalSyncCompletion {
        $currentRun = $this->runs->find($run->id);
        $currentAttempt = $this->runs->attempt($attempt->id);

        if ($currentRun === null || $currentAttempt === null) {
            throw new InvalidArgumentException('The incremental run or attempt no longer exists.');
        }

        $changes = $this->changes->forRun($currentRun);
        $currentCheckpoint = $this->checkpoints->latest($stream, Capability::IncrementalSync, $partitionKey);

        if ($changes === []) {
            if (($currentCheckpoint === null && $expectedVersion !== 0)
                || ($currentCheckpoint !== null && ($currentCheckpoint->value != $nextCheckpoint || $currentCheckpoint->version !== $expectedVersion))
            ) {
                throw new InvalidArgumentException('An unchanged source cannot advance beyond its committed checkpoint.');
            }

            $this->runs->succeedAttempt($currentRun, $currentAttempt, [
                'accepted_changes' => 0,
                'added' => 0,
                'updated' => 0,
                'deleted' => 0,
            ]);

            return new IncrementalSyncCompletion($this->runs->find($currentRun->id) ?? $currentRun, $currentCheckpoint, 0);
        }

        $references = array_values(array_unique(array_map(
            static fn (IncrementalChange $change): string => $change->observationReference,
            $changes,
        )));
        $checkpoint = $this->checkpoints->commit(
            $stream,
            Capability::IncrementalSync,
            $partitionKey,
            $nextCheckpoint,
            $expectedVersion,
            $currentRun,
            $references,
            $currentAttempt,
        );
        $counts = array_count_values(array_map(
            static fn (IncrementalChange $change): string => $change->kind->value,
            $changes,
        ));
        $this->runs->succeedAttempt($currentRun, $currentAttempt, [
            'accepted_changes' => count($changes),
            'added' => $counts[ChangeKind::Added->value] ?? 0,
            'updated' => $counts[ChangeKind::Updated->value] ?? 0,
            'deleted' => $counts[ChangeKind::Deleted->value] ?? 0,
        ], $references);

        return new IncrementalSyncCompletion($this->runs->find($currentRun->id) ?? $currentRun, $checkpoint, count($changes));
    }
}
