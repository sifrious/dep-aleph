<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

final readonly class GitHubRunQueries
{
    public function __construct(private IngestionRunQueries $runs) {}

    public function find(string $id): ?GitHubIngestionRun
    {
        $run = $this->runs->find($id);

        return $run === null ? null : GitHubIngestionRun::from($run);
    }

    public function latest(string $sourceReference): ?GitHubIngestionRun
    {
        $run = $this->runs->latest($sourceReference, Capability::IncrementalSync);

        return $run === null ? null : GitHubIngestionRun::from($run);
    }
}
