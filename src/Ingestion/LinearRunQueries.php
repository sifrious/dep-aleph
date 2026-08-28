<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

final readonly class LinearRunQueries
{
    public function __construct(private IngestionRunQueries $runs) {}

    public function find(string $id): ?LinearIngestionRun
    {
        $run = $this->runs->find($id);

        return $run === null ? null : LinearIngestionRun::from($run);
    }

    public function latest(string $sourceReference): ?LinearIngestionRun
    {
        $run = $this->runs->latest($sourceReference, Capability::IncrementalSync);

        return $run === null ? null : LinearIngestionRun::from($run);
    }
}
