<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Watch;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use stdClass;

final readonly class RepositoryWatches
{
    public function __construct(
        private ConnectionInterface $connection,
        private ConnectorInstallations $installations,
    ) {}

    public function import(LegacyRepositoryWatch $legacy): RepositoryWatch
    {
        $existing = $this->findByLegacyReference($legacy->legacyReference);

        if ($existing !== null) {
            return $existing;
        }

        $this->assertInstallation($legacy->sourceInstallationId);
        $id = RepositoryWatchIdentity::for($legacy->legacyReference);
        $this->table()->insert([
            'id' => $id,
            'legacy_reference' => $legacy->legacyReference,
            'source_installation_id' => $legacy->sourceInstallationId,
            'source_reference' => $legacy->sourceReference,
            'repository_reference' => $legacy->repositoryReference,
            'mode' => $legacy->mode->value,
            'filters' => $this->encode($legacy->filters),
            'cadence_seconds' => $legacy->cadenceSeconds,
            'checkpoint' => $this->encode($legacy->checkpoint),
            'head_reference' => $legacy->headReference,
            'enabled' => $legacy->enabled,
            'last_synced_at' => $legacy->lastSyncedAt,
            'backfill_completed_at' => $legacy->backfillCompletedAt,
            'next_sync_at' => $legacy->nextSyncAt,
            'last_error' => $legacy->lastError,
            'backoff_until' => $legacy->backoffUntil,
            'created_at' => $legacy->createdAt,
            'updated_at' => $legacy->updatedAt,
        ]);

        return $this->find($id) ?? throw new InvalidArgumentException('The repository watch could not be imported.');
    }

    public function find(string $id): ?RepositoryWatch
    {
        $row = $this->table()->where('id', $id)->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    public function findByLegacyReference(string $reference): ?RepositoryWatch
    {
        $row = $this->table()->where('legacy_reference', $reference)->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    public function findByRepository(string $sourceInstallationId, string $repositoryReference): ?RepositoryWatch
    {
        $row = $this->table()
            ->where('source_installation_id', $sourceInstallationId)
            ->where('repository_reference', $repositoryReference)
            ->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    /**
     * @return list<RepositoryWatch>
     */
    public function due(DateTimeImmutable $at, int $limit): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Repository watch due limit must be positive.');
        }

        return array_values($this->table()
            ->where('enabled', true)
            ->where(fn ($query) => $query->whereNull('next_sync_at')->orWhere('next_sync_at', '<=', $at))
            ->where(fn ($query) => $query->whereNull('backoff_until')->orWhere('backoff_until', '<=', $at))
            ->orderByRaw('next_sync_at IS NULL DESC')
            ->orderBy('next_sync_at')
            ->limit($limit)
            ->get()
            ->map(fn (stdClass $row): RepositoryWatch => $this->hydrate($row))
            ->all());
    }

    public function setEnabled(RepositoryWatch $watch, bool $enabled): RepositoryWatch
    {
        $this->table()->where('id', $watch->id)->update([
            'enabled' => $enabled,
            'updated_at' => new DateTimeImmutable,
        ]);

        return $this->find($watch->id) ?? $watch;
    }

    /**
     * @param  array<string, mixed>  $checkpoint
     */
    public function recordSuccess(
        RepositoryWatch $watch,
        array $checkpoint,
        ?string $headReference,
        DateTimeImmutable $syncedAt,
        bool $backfillComplete = false,
    ): RepositoryWatch {
        $this->table()->where('id', $watch->id)->update([
            'checkpoint' => $this->encode($checkpoint),
            'head_reference' => $headReference,
            'last_synced_at' => $syncedAt,
            'backfill_completed_at' => $backfillComplete ? $syncedAt : $watch->backfillCompletedAt,
            'next_sync_at' => $syncedAt->modify('+'.$watch->cadenceSeconds.' seconds'),
            'last_error' => null,
            'backoff_until' => null,
            'updated_at' => $syncedAt,
        ]);

        return $this->find($watch->id) ?? $watch;
    }

    public function recordFailure(
        RepositoryWatch $watch,
        string $message,
        DateTimeImmutable $failedAt,
        ?DateTimeImmutable $backoffUntil = null,
    ): RepositoryWatch {
        if (trim($message) === '') {
            throw new InvalidArgumentException('Repository watch failure requires a message.');
        }

        $backoffUntil ??= $failedAt->modify('+'.min(3600, $watch->cadenceSeconds * 2).' seconds');
        $this->table()->where('id', $watch->id)->update([
            'last_error' => $message,
            'backoff_until' => $backoffUntil,
            'next_sync_at' => $backoffUntil,
            'updated_at' => $failedAt,
        ]);

        return $this->find($watch->id) ?? $watch;
    }

    public function claimTrigger(
        RepositoryWatch $watch,
        string $triggerKey,
        DateTimeImmutable $observedAt,
        ?string $runId = null,
    ): bool {
        if (trim($triggerKey) === '') {
            throw new InvalidArgumentException('Repository watch trigger requires a stable key.');
        }

        try {
            $this->connection->table('aleph_repository_watch_triggers')->insert([
                'id' => (string) Str::ulid(),
                'repository_watch_id' => $watch->id,
                'trigger_key' => $triggerKey,
                'run_id' => $runId,
                'observed_at' => $observedAt,
            ]);
        } catch (QueryException $failure) {
            if ($this->connection->table('aleph_repository_watch_triggers')
                ->where('repository_watch_id', $watch->id)
                ->where('trigger_key', $triggerKey)
                ->exists()) {
                return false;
            }

            throw $failure;
        }

        return true;
    }

    private function assertInstallation(string $id): void
    {
        if ($this->installations->find($id) === null) {
            throw new InvalidArgumentException("Source installation [{$id}] does not exist.");
        }
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function encode(array $value): ?string
    {
        return $value === [] ? null : json_encode($value, JSON_THROW_ON_ERROR);
    }

    private function table(): Builder
    {
        return $this->connection->table('aleph_repository_watches');
    }

    private function hydrate(stdClass $row): RepositoryWatch
    {
        return new RepositoryWatch(
            id: (string) $row->id,
            legacyReference: $row->legacy_reference === null ? null : (string) $row->legacy_reference,
            sourceInstallationId: (string) $row->source_installation_id,
            sourceReference: (string) $row->source_reference,
            repositoryReference: (string) $row->repository_reference,
            mode: RepositoryWatchMode::from((string) $row->mode),
            filters: $this->decode($row->filters),
            cadenceSeconds: (int) $row->cadence_seconds,
            checkpoint: $this->decode($row->checkpoint),
            headReference: $row->head_reference === null ? null : (string) $row->head_reference,
            enabled: (bool) $row->enabled,
            lastSyncedAt: $this->time($row->last_synced_at),
            backfillCompletedAt: $this->time($row->backfill_completed_at),
            nextSyncAt: $this->time($row->next_sync_at),
            lastError: $row->last_error === null ? null : (string) $row->last_error,
            backoffUntil: $this->time($row->backoff_until),
            createdAt: new DateTimeImmutable((string) $row->created_at),
            updatedAt: new DateTimeImmutable((string) $row->updated_at),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    private function time(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable((string) $value);
    }
}
