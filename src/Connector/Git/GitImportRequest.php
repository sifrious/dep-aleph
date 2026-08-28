<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Git;

final readonly class GitImportRequest
{
    public function __construct(
        public string $sourceReference,
        public string $ref,
        public string $streamId,
        public string $runId,
        public string $attemptId,
        public int $expectedCheckpointVersion,
    ) {}
}
