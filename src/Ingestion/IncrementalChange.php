<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;

final readonly class IncrementalChange
{
    public function __construct(
        public string $id,
        public string $sourceStreamId,
        public string $runId,
        public ?string $attemptId,
        public string $partitionKey,
        public string $sourceChangeId,
        public ChangeKind $kind,
        public string $resourceReference,
        public string $fingerprint,
        public string $observationReference,
        public DateTimeImmutable $occurredAt,
        public DateTimeImmutable $recordedAt,
    ) {}
}
