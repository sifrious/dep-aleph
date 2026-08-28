<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

final readonly class IncrementalSyncRequest
{
    public function __construct(
        public string $sourceStreamId,
        public string $sourceReference,
        public string $partitionKey,
        public bool $fullReconciliation,
        public ContinuationBudget $budget,
        public string $idempotencyKey,
        public LaunchAuthorization $authorization,
    ) {}
}
