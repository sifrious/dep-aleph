<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Linear;

use Sifrious\Aleph\Ingestion\Capability;

final readonly class LinearImportRequest
{
    /**
     * @param  list<LinearStream>  $streams
     * @param  array<string, int>  $expectedCheckpointVersions
     */
    public function __construct(
        public string $sourceReference,
        public string $streamId,
        public string $runId,
        public string $attemptId,
        public array $streams,
        public array $expectedCheckpointVersions,
        public Capability $capability,
        public int $pageSize = 100,
    ) {}
}
