<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;

final readonly class IngestionCheckpoint
{
    /**
     * @param  list<string>  $acceptedReferences
     */
    public function __construct(
        public string $id,
        public string $sourceStreamId,
        public Capability $capability,
        public string $partitionKey,
        public CheckpointValue $value,
        public int $version,
        public array $acceptedReferences,
        public string $runId,
        public ?string $attemptId,
        public DateTimeImmutable $committedAt,
    ) {}
}
