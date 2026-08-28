<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;

final readonly class IngestionPartition
{
    /**
     * @param  array<string, mixed>  $checkpoint
     */
    public function __construct(
        public string $id,
        public string $runId,
        public string $key,
        public int $position,
        public PartitionStatus $status,
        public array $checkpoint,
        public int $processed,
        public int $accepted,
        public int $failed,
        public ?string $failure,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'position' => $this->position,
            'status' => $this->status->value,
            'checkpoint' => $this->checkpoint,
            'processed' => $this->processed,
            'accepted' => $this->accepted,
            'failed' => $this->failed,
            'failure' => $this->failure,
            'started_at' => $this->startedAt?->format(DATE_ATOM),
            'finished_at' => $this->finishedAt?->format(DATE_ATOM),
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
        ];
    }
}
