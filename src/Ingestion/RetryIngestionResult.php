<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

final readonly class RetryIngestionResult
{
    public function __construct(
        public IngestionRun $run,
        public IngestionAttempt $attempt,
        public bool $replayed,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run' => $this->run->toArray(),
            'attempt' => $this->attempt->toArray(),
            'replayed' => $this->replayed,
        ];
    }
}
