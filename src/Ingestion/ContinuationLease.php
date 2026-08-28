<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;

final readonly class ContinuationLease
{
    public function __construct(
        public string $id,
        public string $sourceStreamId,
        public Capability $capability,
        public string $partitionKey,
        public string $runId,
        public string $owner,
        public DateTimeImmutable $acquiredAt,
        public DateTimeImmutable $expiresAt,
    ) {}
}
