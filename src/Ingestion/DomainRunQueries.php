<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

final readonly class DomainRunQueries
{
    public function __construct(private IngestionRunQueries $runs) {}

    public function find(string $id): ?DomainIngestionRun
    {
        $run = $this->runs->find($id);

        return $run === null ? null : DomainIngestionRun::from($run);
    }

    public function latest(string $sourceReference): ?DomainIngestionRun
    {
        $run = $this->runs->latest($sourceReference, Capability::IncrementalSync);

        return $run === null ? null : DomainIngestionRun::from($run);
    }
}
