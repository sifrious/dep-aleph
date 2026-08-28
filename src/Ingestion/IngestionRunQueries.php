<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

final readonly class IngestionRunQueries
{
    public function __construct(private IngestionRuns $runs) {}

    public function find(string $id): ?IngestionRunReadModel
    {
        $run = $this->runs->find($id);

        return $run === null ? null : new IngestionRunReadModel($run, $this->runs->attempts($run));
    }

    public function latest(string $sourceReference, Capability $capability): ?IngestionRunReadModel
    {
        $run = $this->runs->latest($sourceReference, $capability);

        return $run === null ? null : new IngestionRunReadModel($run, $this->runs->attempts($run));
    }
}
