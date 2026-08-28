<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use stdClass;

final readonly class IngestionRuns
{
    public function __construct(private ConnectionInterface $connection) {}

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $checkpoint
     */
    public function start(
        string $sourceReference,
        Capability $capability,
        array $parameters,
        ?string $connectorId = null,
        ?string $sourceInstallationId = null,
        ?string $idempotencyKey = null,
        array $checkpoint = [],
    ): IngestionRun {
        if ($idempotencyKey !== null) {
            $existing = $this->findByIdempotencyKey($idempotencyKey);

            if ($existing !== null) {
                return $existing;
            }
        }

        $run = new IngestionRun(
            id: (string) Str::ulid(),
            sourceReference: $sourceReference,
            capability: $capability,
            status: RunStatus::Running,
            parameters: $parameters,
            startedAt: new DateTimeImmutable,
            connectorId: $connectorId,
            sourceInstallationId: $sourceInstallationId,
            idempotencyKey: $idempotencyKey,
            checkpoint: $checkpoint,
        );

        $this->insert($run);

        return $run;
    }

    public function import(LegacySyncRun $legacy): IngestionRun
    {
        $existing = $this->findByLegacyReference($legacy->legacyReference);

        if ($existing !== null) {
            return $existing;
        }

        $run = new IngestionRun(
            id: LegacyRunIdentity::for($legacy->legacyReference),
            sourceReference: $legacy->sourceReference,
            capability: $legacy->capability,
            status: $legacy->status,
            parameters: $legacy->parameters,
            startedAt: $legacy->startedAt,
            connectorId: $legacy->connectorId,
            sourceInstallationId: $legacy->sourceInstallationId,
            legacyReference: $legacy->legacyReference,
            completeness: $legacy->completeness,
            checkpoint: $legacy->checkpoint,
            stats: $legacy->stats,
            failure: $legacy->failure,
            acceptedReferences: $legacy->acceptedReferences,
            finishedAt: $legacy->finishedAt,
        );

        $this->insert($run);

        return $run;
    }

    public function latestIncomplete(string $sourceReference, Capability $capability): ?IngestionRun
    {
        $row = $this->table()
            ->where('source_reference', $sourceReference)
            ->where('capability', $capability->value)
            ->whereIn('status', [
                RunStatus::Pending->value,
                RunStatus::Running->value,
                RunStatus::Partial->value,
                RunStatus::Interrupted->value,
            ])
            ->orderByDesc('id')
            ->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function find(string $id): ?IngestionRun
    {
        $row = $this->table()->where('id', $id)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function latest(string $sourceReference, Capability $capability): ?IngestionRun
    {
        $row = $this->table()
            ->where('source_reference', $sourceReference)
            ->where('capability', $capability->value)
            ->orderByDesc('id')
            ->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function findByIdempotencyKey(string $key): ?IngestionRun
    {
        $row = $this->table()->where('idempotency_key', $key)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function findByLegacyReference(string $reference): ?IngestionRun
    {
        $row = $this->table()->where('legacy_reference', $reference)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function resume(IngestionRun $run): IngestionRun
    {
        $this->table()->where('id', $run->id)->update([
            'status' => RunStatus::Running->value,
            'error' => null,
            'finished_at' => null,
        ]);

        return new IngestionRun(
            id: $run->id,
            sourceReference: $run->sourceReference,
            capability: $run->capability,
            status: RunStatus::Running,
            parameters: $run->parameters,
            startedAt: $run->startedAt,
            connectorId: $run->connectorId,
            sourceInstallationId: $run->sourceInstallationId,
            legacyReference: $run->legacyReference,
            idempotencyKey: $run->idempotencyKey,
            completeness: RunCompleteness::Incomplete,
            checkpoint: $run->checkpoint,
            stats: $run->stats,
            acceptedReferences: $run->acceptedReferences,
        );
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    public function complete(IngestionRun $run, array $stats): void
    {
        $this->table()->where('id', $run->id)->update([
            'status' => RunStatus::Completed->value,
            'completeness' => RunCompleteness::Complete->value,
            'stats' => json_encode($stats, JSON_THROW_ON_ERROR),
            'error' => null,
            'finished_at' => Carbon::now(),
        ]);
    }

    public function interrupt(IngestionRun $run, string $error): void
    {
        $this->table()->where('id', $run->id)->update([
            'status' => RunStatus::Interrupted->value,
            'completeness' => RunCompleteness::Incomplete->value,
            'error' => $error,
            'failure' => json_encode((new RunFailure('interrupted', $error, true))->toArray(), JSON_THROW_ON_ERROR),
            'finished_at' => Carbon::now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $checkpoint
     */
    public function checkpoint(IngestionRun $run, array $checkpoint): void
    {
        $this->table()->where('id', $run->id)->update([
            'checkpoint' => json_encode($checkpoint, JSON_THROW_ON_ERROR),
        ]);
    }

    private function insert(IngestionRun $run): void
    {
        $this->table()->insert([
            'id' => $run->id,
            'connector_id' => $run->connectorId,
            'source_installation_id' => $run->sourceInstallationId,
            'legacy_reference' => $run->legacyReference,
            'idempotency_key' => $run->idempotencyKey,
            'source_reference' => $run->sourceReference,
            'capability' => $run->capability->value,
            'status' => $run->status->value,
            'completeness' => $run->completeness->value,
            'parameters' => json_encode($run->parameters, JSON_THROW_ON_ERROR),
            'checkpoint' => $run->checkpoint === [] ? null : json_encode($run->checkpoint, JSON_THROW_ON_ERROR),
            'stats' => $run->stats === [] ? null : json_encode($run->stats, JSON_THROW_ON_ERROR),
            'error' => $run->failure?->message,
            'failure' => $run->failure === null ? null : json_encode($run->failure->toArray(), JSON_THROW_ON_ERROR),
            'accepted_references' => $run->acceptedReferences === [] ? null : json_encode($run->acceptedReferences, JSON_THROW_ON_ERROR),
            'started_at' => $run->startedAt,
            'finished_at' => $run->finishedAt,
        ]);
    }

    private function hydrate(stdClass $row): IngestionRun
    {
        $parameters = json_decode((string) $row->parameters, true, 512, JSON_THROW_ON_ERROR);
        $checkpoint = $row->checkpoint === null ? [] : json_decode((string) $row->checkpoint, true, 512, JSON_THROW_ON_ERROR);
        $stats = $row->stats === null ? [] : json_decode((string) $row->stats, true, 512, JSON_THROW_ON_ERROR);
        $failure = $row->failure === null ? null : json_decode((string) $row->failure, true, 512, JSON_THROW_ON_ERROR);
        $acceptedReferences = $row->accepted_references === null ? [] : json_decode((string) $row->accepted_references, true, 512, JSON_THROW_ON_ERROR);

        return new IngestionRun(
            id: (string) $row->id,
            sourceReference: (string) $row->source_reference,
            capability: Capability::from((string) $row->capability),
            status: RunStatus::from((string) $row->status),
            parameters: is_array($parameters) ? $parameters : [],
            startedAt: new DateTimeImmutable((string) $row->started_at),
            connectorId: $row->connector_id === null ? null : (string) $row->connector_id,
            sourceInstallationId: $row->source_installation_id === null ? null : (string) $row->source_installation_id,
            legacyReference: $row->legacy_reference === null ? null : (string) $row->legacy_reference,
            idempotencyKey: $row->idempotency_key === null ? null : (string) $row->idempotency_key,
            completeness: RunCompleteness::from((string) $row->completeness),
            checkpoint: is_array($checkpoint) ? $checkpoint : [],
            stats: is_array($stats) ? $stats : [],
            failure: is_array($failure) ? RunFailure::fromArray($failure) : null,
            acceptedReferences: is_array($acceptedReferences) ? array_values(array_map(strval(...), $acceptedReferences)) : [],
            finishedAt: $row->finished_at === null ? null : new DateTimeImmutable((string) $row->finished_at),
        );
    }

    private function table(): Builder
    {
        return $this->connection->table('aleph_ingestion_runs');
    }
}
