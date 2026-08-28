<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;

final readonly class ResumeIngestionRequest
{
    /**
     * @param  list<string>  $partitionKeys
     */
    public function __construct(
        public string $runId,
        public string $sourceStreamId,
        public array $partitionKeys,
        public string $leaseOwner,
        public ContinuationBudget $budget,
        public int $leaseSeconds,
        public DateTimeImmutable $requestedAt,
    ) {}
}
