<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

final readonly class IncrementalSyncCompletion
{
    public function __construct(
        public IngestionRun $run,
        public ?IngestionCheckpoint $checkpoint,
        public int $acceptedChanges,
    ) {}
}
