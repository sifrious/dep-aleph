<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;

final readonly class SourceStreamStatus
{
    public function __construct(
        public string $sourceStreamId,
        public ?string $lastAttemptId,
        public ?string $lastSuccessfulRunId,
        public ?DateTimeImmutable $lastSuccessAt,
        public ?DateTimeImmutable $acceptedThroughAt,
        public ?DateTimeImmutable $nextDueAt,
        public FreshnessStatus $freshness,
        public ?FreshnessExpectation $expectation,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_stream_id' => $this->sourceStreamId,
            'last_attempt_id' => $this->lastAttemptId,
            'last_successful_run_id' => $this->lastSuccessfulRunId,
            'last_success_at' => $this->lastSuccessAt?->format(DATE_ATOM),
            'accepted_through_at' => $this->acceptedThroughAt?->format(DATE_ATOM),
            'next_due_at' => $this->nextDueAt?->format(DATE_ATOM),
            'freshness_status' => $this->freshness->value,
            'has_synchronized' => $this->lastSuccessAt !== null,
        ];
    }
}
