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
        $this->assertTransition($run, RunStatus::Running);

        if ($run->status === RunStatus::Failed && $run->failure?->retryable !== true) {
            throw InvalidRunTransition::from($run->status, RunStatus::Running);
        }

        $this->table()->where('id', $run->id)->update([
            'status' => RunStatus::Running->value,
            'error' => null,
            'failure' => null,
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

    public function beginAttempt(IngestionRun $run): IngestionAttempt
    {
        $active = $this->attemptsTable()
            ->where('run_id', $run->id)
            ->where('status', RunStatus::Running->value)
            ->first();

        if ($active !== null) {
            return $this->hydrateAttempt($active);
        }

        $current = $run->status === RunStatus::Running ? $run : $this->resume($run);
        $number = ((int) $this->attemptsTable()->where('run_id', $run->id)->max('number')) + 1;
        $attempt = new IngestionAttempt(
            id: (string) Str::ulid(),
            runId: $run->id,
            number: $number,
            status: RunStatus::Running,
            checkpoint: $current->checkpoint,
            stats: $current->stats,
            failure: null,
            startedAt: new DateTimeImmutable,
            finishedAt: null,
        );

        $this->attemptsTable()->insert([
            'id' => $attempt->id,
            'run_id' => $attempt->runId,
            'number' => $attempt->number,
            'status' => $attempt->status->value,
            'checkpoint' => $attempt->checkpoint === [] ? null : json_encode($attempt->checkpoint, JSON_THROW_ON_ERROR),
            'stats' => $attempt->stats === [] ? null : json_encode($attempt->stats, JSON_THROW_ON_ERROR),
            'failure' => null,
            'started_at' => $attempt->startedAt,
            'finished_at' => null,
        ]);

        return $attempt;
    }

    /**
     * @param  array<string, mixed>  $checkpoint
     * @param  array<string, int|float>  $stats
     * @param  list<string>  $acceptedReferences
     */
    public function recordProgress(
        IngestionRun $run,
        IngestionAttempt $attempt,
        array $checkpoint,
        array $stats,
        array $acceptedReferences = [],
    ): void {
        $this->assertActiveAttempt($run, $attempt);
        $references = $this->mergeAcceptedReferences($run, $acceptedReferences);

        $this->attemptsTable()->where('id', $attempt->id)->update([
            'checkpoint' => json_encode($checkpoint, JSON_THROW_ON_ERROR),
            'stats' => json_encode($stats, JSON_THROW_ON_ERROR),
        ]);
        $this->table()->where('id', $run->id)->update([
            'checkpoint' => json_encode($checkpoint, JSON_THROW_ON_ERROR),
            'stats' => json_encode($stats, JSON_THROW_ON_ERROR),
            'accepted_references' => json_encode($references, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @param  array<string, int|float>  $stats
     * @param  list<string>  $acceptedReferences
     */
    public function succeedAttempt(
        IngestionRun $run,
        IngestionAttempt $attempt,
        array $stats,
        array $acceptedReferences = [],
    ): void {
        $this->assertActiveAttempt($run, $attempt);
        $this->assertTransition($run, RunStatus::Completed);
        $references = $this->mergeAcceptedReferences($run, $acceptedReferences);
        $finishedAt = Carbon::now();

        $this->attemptsTable()->where('id', $attempt->id)->update([
            'status' => RunStatus::Completed->value,
            'stats' => json_encode($stats, JSON_THROW_ON_ERROR),
            'failure' => null,
            'finished_at' => $finishedAt,
        ]);
        $this->table()->where('id', $run->id)->update([
            'status' => RunStatus::Completed->value,
            'completeness' => RunCompleteness::Complete->value,
            'stats' => json_encode($stats, JSON_THROW_ON_ERROR),
            'error' => null,
            'failure' => null,
            'accepted_references' => json_encode($references, JSON_THROW_ON_ERROR),
            'finished_at' => $finishedAt,
        ]);
    }

    /**
     * @param  array<string, int|float>  $stats
     * @param  list<string>  $acceptedReferences
     */
    public function failAttempt(
        IngestionRun $run,
        IngestionAttempt $attempt,
        RunFailure $failure,
        array $stats = [],
        array $acceptedReferences = [],
        bool $partial = false,
    ): void {
        $this->assertActiveAttempt($run, $attempt);
        $status = $partial ? RunStatus::Partial : RunStatus::Failed;
        $this->assertTransition($run, $status);
        $completeness = $partial ? RunCompleteness::Partial : RunCompleteness::Incomplete;
        $references = $this->mergeAcceptedReferences($run, $acceptedReferences);
        $finishedAt = Carbon::now();
        $encodedFailure = json_encode($failure->toArray(), JSON_THROW_ON_ERROR);

        $this->attemptsTable()->where('id', $attempt->id)->update([
            'status' => $status->value,
            'stats' => json_encode($stats, JSON_THROW_ON_ERROR),
            'failure' => $encodedFailure,
            'finished_at' => $finishedAt,
        ]);
        $this->table()->where('id', $run->id)->update([
            'status' => $status->value,
            'completeness' => $completeness->value,
            'stats' => json_encode($stats, JSON_THROW_ON_ERROR),
            'error' => $failure->message,
            'failure' => $encodedFailure,
            'accepted_references' => json_encode($references, JSON_THROW_ON_ERROR),
            'finished_at' => $finishedAt,
        ]);
    }

    /**
     * @return list<IngestionAttempt>
     */
    public function attempts(IngestionRun $run): array
    {
        return array_values($this->attemptsTable()
            ->where('run_id', $run->id)
            ->orderBy('number')
            ->get()
            ->map(fn (stdClass $row): IngestionAttempt => $this->hydrateAttempt($row))
            ->all());
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    public function complete(IngestionRun $run, array $stats): void
    {
        $this->assertTransition($run, RunStatus::Completed);

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
        $this->assertTransition($run, RunStatus::Interrupted);

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

    private function hydrateAttempt(stdClass $row): IngestionAttempt
    {
        $checkpoint = $row->checkpoint === null ? [] : json_decode((string) $row->checkpoint, true, 512, JSON_THROW_ON_ERROR);
        $stats = $row->stats === null ? [] : json_decode((string) $row->stats, true, 512, JSON_THROW_ON_ERROR);
        $failure = $row->failure === null ? null : json_decode((string) $row->failure, true, 512, JSON_THROW_ON_ERROR);

        return new IngestionAttempt(
            id: (string) $row->id,
            runId: (string) $row->run_id,
            number: (int) $row->number,
            status: RunStatus::from((string) $row->status),
            checkpoint: is_array($checkpoint) ? $checkpoint : [],
            stats: is_array($stats) ? $stats : [],
            failure: is_array($failure) ? RunFailure::fromArray($failure) : null,
            startedAt: new DateTimeImmutable((string) $row->started_at),
            finishedAt: $row->finished_at === null ? null : new DateTimeImmutable((string) $row->finished_at),
        );
    }

    private function assertActiveAttempt(IngestionRun $run, IngestionAttempt $attempt): void
    {
        if ($attempt->runId !== $run->id || $attempt->status !== RunStatus::Running) {
            throw InvalidRunTransition::from($attempt->status, RunStatus::Running);
        }

        $active = $this->attemptsTable()
            ->where('id', $attempt->id)
            ->where('run_id', $run->id)
            ->where('status', RunStatus::Running->value)
            ->exists();

        if (! $active) {
            throw InvalidRunTransition::from($attempt->status, RunStatus::Running);
        }
    }

    private function assertTransition(IngestionRun $run, RunStatus $status): void
    {
        $current = $this->find($run->id);

        if ($current === null) {
            throw InvalidRunTransition::from($run->status, $status);
        }

        if (! $current->status->canTransitionTo($status)) {
            throw InvalidRunTransition::from($current->status, $status);
        }
    }

    /**
     * @param  list<string>  $references
     * @return list<string>
     */
    private function mergeAcceptedReferences(IngestionRun $run, array $references): array
    {
        $current = $this->find($run->id);

        if ($current === null) {
            return array_values(array_unique([...$run->acceptedReferences, ...$references]));
        }

        return array_values(array_unique([...$current->acceptedReferences, ...$references]));
    }

    private function table(): Builder
    {
        return $this->connection->table('aleph_ingestion_runs');
    }

    private function attemptsTable(): Builder
    {
        return $this->connection->table('aleph_ingestion_attempts');
    }
}
