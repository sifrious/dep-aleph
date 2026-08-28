<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

final readonly class RetryIngestionRequest
{
    public function __construct(
        public string $runId,
        public string $attemptId,
        public string $reason,
        public ?string $partitionKey = null,
    ) {}
}
