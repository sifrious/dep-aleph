<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;
use InvalidArgumentException;

final class LandingSyncRunAdapter
{
    /**
     * @param  array<string, mixed>  $row
     */
    public function adapt(array $row, string $sourceReference): LegacySyncRun
    {
        $legacyId = $row['id'] ?? null;

        if (! is_int($legacyId) && ! is_string($legacyId)) {
            throw new InvalidArgumentException('A Landing sync run requires a stable legacy id.');
        }

        $status = $this->status((string) ($row['status'] ?? 'pending'));
        $steps = is_array($row['steps'] ?? null) ? array_values($row['steps']) : [];
        $error = is_string($row['error'] ?? null) ? $row['error'] : null;
        $finishedAt = $row['finished_at'] ?? null;

        return new LegacySyncRun(
            legacyReference: 'landing:sync-run/'.$legacyId,
            connectorId: 'github',
            sourceReference: $sourceReference,
            capability: Capability::IncrementalSync,
            status: $status,
            completeness: $status === RunStatus::Completed
                ? RunCompleteness::Complete
                : RunCompleteness::Incomplete,
            parameters: array_filter([
                'label' => $row['label'] ?? null,
                'branch' => $row['branch'] ?? null,
                'repository_reference' => $row['repository_reference'] ?? null,
                'repo_watch_reference' => $row['repo_watch_reference'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
            checkpoint: [
                'current_step' => $row['current_step_key'] ?? null,
                'steps' => $steps,
            ],
            stats: $this->stats($steps),
            failure: $error === null ? null : new RunFailure('landing.sync_run', $error, false),
            acceptedReferences: is_array($row['accepted_references'] ?? null)
                ? array_values(array_map(strval(...), $row['accepted_references']))
                : [],
            startedAt: new DateTimeImmutable((string) ($row['started_at'] ?? $row['created_at'] ?? 'now')),
            finishedAt: $finishedAt === null
                ? null
                : new DateTimeImmutable((string) $finishedAt),
        );
    }

    private function status(string $status): RunStatus
    {
        return match ($status) {
            'pending' => RunStatus::Pending,
            'running' => RunStatus::Running,
            'succeeded' => RunStatus::Completed,
            'failed' => RunStatus::Failed,
            default => throw new InvalidArgumentException("Unsupported Landing sync-run status [{$status}]."),
        };
    }

    /**
     * @param  list<mixed>  $steps
     * @return array<string, int>
     */
    private function stats(array $steps): array
    {
        $completed = 0;
        $failed = 0;

        foreach ($steps as $step) {
            if (! is_array($step) || ($step['group'] ?? false)) {
                continue;
            }

            $completed += ($step['status'] ?? null) === 'done' ? 1 : 0;
            $failed += ($step['status'] ?? null) === 'failed' ? 1 : 0;
        }

        return ['steps_total' => count(array_filter($steps, static fn (mixed $step): bool => is_array($step) && ! ($step['group'] ?? false))), 'steps_completed' => $completed, 'steps_failed' => $failed];
    }
}
