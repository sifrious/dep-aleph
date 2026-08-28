<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

final readonly class LaunchIngestionResult
{
    public function __construct(
        public IngestionRun $run,
        public bool $replayed,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run' => $this->run->toArray(),
            'replayed' => $this->replayed,
        ];
    }
}
