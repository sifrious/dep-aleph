<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GitHub;

use Sifrious\Aleph\Ingestion\Capability;

final readonly class GitHubImportRequest
{
    public function __construct(
        public string $sourceReference,
        public string $repository,
        public string $streamId,
        public string $runId,
        public string $attemptId,
        public int $expectedCheckpointVersion,
        public Capability $capability,
        public int $pageSize = 100,
    ) {}
}
