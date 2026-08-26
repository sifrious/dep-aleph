<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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

        if ($row === null) {
            return null;
        }

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

    public function resume(IngestionRun $run): IngestionRun
    {
        $this->table()->where('id', $run->id)->update([
            'status' => RunStatus::Running->value,
            'failure' => null,
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
     * @param  array<string, mixed>  $totals
     */
    public function complete(IngestionRun $run, array $totals): void
    {
        $this->table()->where('id', $run->id)->update([
            'status' => RunStatus::Completed->value,
            'totals' => json_encode($totals, JSON_THROW_ON_ERROR),
            'failure' => null,
            'finished_at' => Carbon::now(),
        ]);
    }

    public function interrupt(IngestionRun $run, string $failure): void
    {
        $this->table()->where('id', $run->id)->update([
            'status' => RunStatus::Interrupted->value,
            'failure' => $failure,
            'finished_at' => Carbon::now(),
        ]);
    }

    private function table(): Builder
    {
        return $this->connection->table('aleph_ingestion_runs');
    }
}
