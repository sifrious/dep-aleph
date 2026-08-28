<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;

final readonly class IngestionAttempt
{
    /**
     * @param  array<string, mixed>  $checkpoint
     * @param  array<string, int|float>  $stats
     */
    public function __construct(
        public string $id,
        public string $runId,
        public int $number,
        public RunStatus $status,
        public array $checkpoint,
        public array $stats,
        public ?RunFailure $failure,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'checkpoint' => $this->checkpoint,
            'stats' => $this->stats,
            'failure' => $this->failure?->toArray(),
            'started_at' => $this->startedAt->format(DATE_ATOM),
            'finished_at' => $this->finishedAt?->format(DATE_ATOM),
        ];
    }
}
