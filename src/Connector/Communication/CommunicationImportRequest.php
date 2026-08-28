<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Communication;

use Sifrious\Aleph\Ingestion\Capability;

final readonly class CommunicationImportRequest
{
    public function __construct(
        public CommunicationProvider $provider,
        public string $sourceReference,
        public string $streamId,
        public string $runId,
        public string $attemptId,
        public int $expectedCheckpointVersion,
        public Capability $capability,
        public int $pageSize,
        public int $maxPages,
    ) {}
}
