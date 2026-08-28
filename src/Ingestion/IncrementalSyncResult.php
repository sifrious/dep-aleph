<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

final readonly class IncrementalSyncResult
{
    public function __construct(
        public SourceStream $stream,
        public IngestionRun $run,
        public IngestionAttempt $attempt,
        public ?IngestionCheckpoint $checkpoint,
        public bool $replayed,
    ) {}
}
