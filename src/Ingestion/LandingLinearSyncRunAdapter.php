<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;
use InvalidArgumentException;

final class LandingLinearSyncRunAdapter
{
    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $projectReferences
     * @param  list<string>  $providerReconciliationIds
     * @param  list<string>  $acceptedReferences
     */
    public function adapt(
        array $row,
        string $workspaceReference,
        array $projectReferences = [],
        array $providerReconciliationIds = [],
        array $acceptedReferences = [],
        ?string $providerRunId = null,
        ?string $sourceInstallationId = null,
    ): LegacySyncRun {
        $legacyId = $row['id'] ?? null;

        if (! is_int($legacyId) && ! is_string($legacyId)) {
            throw new InvalidArgumentException('A Landing Linear sync run requires a stable legacy id.');
        }

        $scope = LinearRunScope::from((string) ($row['scope'] ?? 'all'));
        $status = $this->status((string) ($row['status'] ?? 'pending'));
        $error = is_string($row['error'] ?? null) ? $row['error'] : null;
        $finishedAt = $row['finished_at'] ?? null;

        return new LegacySyncRun(
            legacyReference: 'landing:linear-sync-run/'.$legacyId,
            connectorId: 'linear',
            sourceReference: $workspaceReference,
            capability: Capability::IncrementalSync,
            status: $status,
            completeness: $status === RunStatus::Completed ? RunCompleteness::Complete : RunCompleteness::Incomplete,
            parameters: [
                'label' => $row['label'] ?? null,
                'targets' => is_array($row['targets'] ?? null) ? $row['targets'] : [],
                'extensions' => [
                    'linear' => [
                        'scope' => $scope->value,
                        'workspace' => $workspaceReference,
                        'projects' => $projectReferences,
                        'provider_run_id' => $providerRunId,
                        'provider_reconciliation_ids' => $providerReconciliationIds,
                    ],
                ],
            ],
            checkpoint: is_array($row['checkpoint'] ?? null) ? $row['checkpoint'] : [],
            stats: $this->numericStats($row['stats'] ?? []),
            failure: $error === null ? null : new RunFailure(
                kind: 'landing.linear_sync_run',
                message: $error,
                retryable: (bool) ($row['retryable'] ?? false),
            ),
            acceptedReferences: $acceptedReferences,
            startedAt: new DateTimeImmutable((string) ($row['started_at'] ?? $row['created_at'] ?? 'now')),
            finishedAt: $finishedAt === null ? null : new DateTimeImmutable((string) $finishedAt),
            sourceInstallationId: $sourceInstallationId,
        );
    }

    private function status(string $status): RunStatus
    {
        return match ($status) {
            'pending' => RunStatus::Pending,
            'running' => RunStatus::Running,
            'succeeded' => RunStatus::Completed,
            'failed' => RunStatus::Failed,
            default => throw new InvalidArgumentException("Unsupported Landing Linear sync-run status [{$status}]."),
        };
    }

    /** @return array<string, int|float> */
    private function numericStats(mixed $stats): array
    {
        if (! is_array($stats)) {
            return [];
        }

        $numeric = [];

        foreach ($stats as $key => $value) {
            if (is_string($key) && (is_int($value) || is_float($value))) {
                $numeric[$key] = $value;
            }
        }

        return $numeric;
    }
}
