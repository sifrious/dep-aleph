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
     */
    public function start(string $sourceReference, Capability $capability, array $parameters): IngestionRun
    {
        $run = new IngestionRun(
            id: (string) Str::ulid(),
            sourceReference: $sourceReference,
            capability: $capability,
            status: RunStatus::Running,
            parameters: $parameters,
            startedAt: new DateTimeImmutable,
        );

        $this->table()->insert([
            'id' => $run->id,
            'source_reference' => $run->sourceReference,
            'capability' => $run->capability->value,
            'status' => $run->status->value,
            'parameters' => json_encode($run->parameters, JSON_THROW_ON_ERROR),
            'started_at' => $run->startedAt,
        ]);

        return $run;
    }

    public function latestIncomplete(string $sourceReference, Capability $capability): ?IngestionRun
    {
        $row = $this->table()
            ->where('source_reference', $sourceReference)
            ->where('capability', $capability->value)
            ->whereIn('status', [RunStatus::Running->value, RunStatus::Interrupted->value])
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

    public function resume(IngestionRun $run): IngestionRun
    {
        $this->table()->where('id', $run->id)->update([
            'status' => RunStatus::Running->value,
            'error' => null,
            'finished_at' => null,
        ]);

        return new IngestionRun(
            $run->id,
            $run->sourceReference,
            $run->capability,
            RunStatus::Running,
            $run->parameters,
            $run->startedAt,
        );
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    public function complete(IngestionRun $run, array $stats): void
    {
        $this->table()->where('id', $run->id)->update([
            'status' => RunStatus::Completed->value,
            'stats' => json_encode($stats, JSON_THROW_ON_ERROR),
            'error' => null,
            'finished_at' => Carbon::now(),
        ]);
    }

    public function interrupt(IngestionRun $run, string $error): void
    {
        $this->table()->where('id', $run->id)->update([
            'status' => RunStatus::Interrupted->value,
            'error' => $error,
            'finished_at' => Carbon::now(),
        ]);
    }

    private function hydrate(stdClass $row): IngestionRun
    {
        $parameters = json_decode((string) $row->parameters, true, 512, JSON_THROW_ON_ERROR);

        return new IngestionRun(
            id: (string) $row->id,
            sourceReference: (string) $row->source_reference,
            capability: Capability::from((string) $row->capability),
            status: RunStatus::from((string) $row->status),
            parameters: is_array($parameters) ? $parameters : [],
            startedAt: new DateTimeImmutable((string) $row->started_at),
        );
    }

    private function table(): Builder
    {
        return $this->connection->table('aleph_ingestion_runs');
    }
}
