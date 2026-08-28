<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Email;

use Sifrious\Aleph\Ingestion\Capability;

final readonly class EmailImportRequest
{
    public function __construct(
        public string $sourceReference,
        public string $streamId,
        public string $runId,
        public string $attemptId,
        public int $expectedCheckpointVersion,
        public Capability $capability,
        public int $pageSize = 100,
        public int $maxPages = 100,
    ) {}
}
