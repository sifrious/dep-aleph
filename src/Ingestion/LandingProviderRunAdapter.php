<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;
use InvalidArgumentException;

final class LandingProviderRunAdapter
{
    /**
     * @param  array<string, mixed>  $row
     */
    public function adapt(
        string $connectorId,
        array $row,
        string $sourceReference,
        Capability $capability,
        ?string $sourceInstallationId = null,
    ): LegacySyncRun {
        $legacyId = $row['id'] ?? null;

        if (! in_array($connectorId, ['slack', 'github', 'linear', 'dns'], true)) {
            throw new InvalidArgumentException("Unsupported Landing provider run [{$connectorId}].");
        }

        if (! is_int($legacyId) && ! is_string($legacyId)) {
            throw new InvalidArgumentException('A Landing provider run requires a stable legacy id.');
        }

        $status = $this->status((string) ($row['status'] ?? 'pending'));
        $error = is_string($row['error'] ?? null) ? $row['error'] : null;
        $stats = is_array($row['stats'] ?? null) ? $this->numericStats($row['stats']) : [];
        $finishedAt = $row['finished_at'] ?? null;

        return new LegacySyncRun(
            legacyReference: "landing:{$connectorId}-sync-run/{$legacyId}",
            connectorId: $connectorId,
            sourceReference: $sourceReference,
            capability: $capability,
            status: $status,
            completeness: $status === RunStatus::Completed
                ? RunCompleteness::Complete
                : RunCompleteness::Incomplete,
            parameters: array_filter([
                'scope' => $row['scope'] ?? null,
                'label' => $row['label'] ?? null,
                'targets' => is_array($row['targets'] ?? null) ? $row['targets'] : null,
                'project_reference' => $row['project_reference'] ?? null,
                'repo_watch_reference' => $row['repo_watch_reference'] ?? null,
                'domain_reference' => $row['domain_reference'] ?? null,
                'provider_account_reference' => $row['provider_account_reference'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
            checkpoint: is_array($row['checkpoint'] ?? null) ? $row['checkpoint'] : [],
            stats: $stats,
            failure: $error === null ? null : new RunFailure(
                kind: 'landing.provider_run',
                message: $error,
                retryable: (bool) ($row['retryable'] ?? false),
            ),
            acceptedReferences: is_array($row['accepted_references'] ?? null)
                ? array_values(array_map(strval(...), $row['accepted_references']))
                : [],
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
            default => throw new InvalidArgumentException("Unsupported Landing provider-run status [{$status}]."),
        };
    }

    /**
     * @param  array<array-key, mixed>  $stats
     * @return array<string, int|float>
     */
    private function numericStats(array $stats): array
    {
        $numeric = [];

        foreach ($stats as $key => $value) {
            if (is_string($key) && (is_int($value) || is_float($value))) {
                $numeric[$key] = $value;
            }
        }

        return $numeric;
    }
}
