<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

use Sifrious\Aleph\Ingestion\Capability;

final readonly class SlackImportRequest
{
    /**
     * @param  list<string>  $partitions
     * @param  array<string, int>  $expectedVersions
     */
    public function __construct(
        public string $sourceReference,
        public string $streamId,
        public string $runId,
        public string $attemptId,
        public array $partitions,
        public array $expectedVersions,
        public Capability $capability,
        public int $pageSize = 200,
        public int $maxPages = 100,
    ) {}
}
