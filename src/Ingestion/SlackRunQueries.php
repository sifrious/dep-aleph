<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

final readonly class SlackRunQueries
{
    public function __construct(private IngestionRunQueries $runs) {}

    public function find(string $id): ?SlackIngestionRun
    {
        $run = $this->runs->find($id);

        return $run === null ? null : SlackIngestionRun::from($run);
    }

    public function latest(string $sourceReference): ?SlackIngestionRun
    {
        $run = $this->runs->latest($sourceReference, Capability::IncrementalSync);

        return $run === null ? null : SlackIngestionRun::from($run);
    }
}
