<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

final readonly class BackfillResult
{
    /**
     * @param  list<IngestionPartition>  $partitions
     */
    public function __construct(
        public IngestionRun $run,
        public IngestionAttempt $attempt,
        public array $partitions,
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
            'partitions' => array_map(
                static fn (IngestionPartition $partition): array => $partition->toArray(),
                $this->partitions,
            ),
            'replayed' => $this->replayed,
        ];
    }
}
