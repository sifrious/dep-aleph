<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Watch;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class LandingRepoWatchAdapter
{
    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $checkpoint
     */
    public function adapt(
        array $row,
        string $sourceInstallationId,
        string $sourceReference,
        string $repositoryReference,
        RepositoryWatchMode $mode = RepositoryWatchMode::Poll,
        array $filters = [],
        array $checkpoint = [],
    ): LegacyRepositoryWatch {
        $id = $row['id'] ?? null;

        if ((! is_int($id) && ! is_string($id)) || trim($sourceInstallationId) === ''
            || trim($sourceReference) === '' || trim($repositoryReference) === '') {
            throw new InvalidArgumentException('A Landing repository watch requires stable watch, source, installation, and repository references.');
        }

        $cadenceMinutes = $row['cadence_minutes'] ?? 5;

        if (! is_int($cadenceMinutes) || $cadenceMinutes < 1) {
            throw new InvalidArgumentException('Landing repository watch cadence must be a positive integer.');
        }

        return new LegacyRepositoryWatch(
            legacyReference: 'landing:repo-watch/'.$id,
            sourceInstallationId: $sourceInstallationId,
            sourceReference: $sourceReference,
            repositoryReference: $repositoryReference,
            mode: $mode,
            filters: $filters,
            cadenceSeconds: $cadenceMinutes * 60,
            checkpoint: $checkpoint,
            headReference: is_string($row['last_indexed_ref'] ?? null) ? $row['last_indexed_ref'] : null,
            enabled: (bool) ($row['enabled'] ?? true),
            lastSyncedAt: $this->time($row['last_synced_at'] ?? null),
            backfillCompletedAt: $this->time($row['backfill_completed_at'] ?? null),
            nextSyncAt: $this->time($row['next_sync_at'] ?? null),
            lastError: is_string($row['last_error'] ?? null) ? $row['last_error'] : null,
            backoffUntil: $this->time($row['backoff_until'] ?? null),
            createdAt: $this->time($row['created_at'] ?? null) ?? new DateTimeImmutable,
            updatedAt: $this->time($row['updated_at'] ?? null) ?? new DateTimeImmutable,
        );
    }

    private function time(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable((string) $value);
    }
}
