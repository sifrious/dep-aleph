<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;

final readonly class IngestionFailure
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public string $id,
        public string $runId,
        public ?string $attemptId,
        public ?string $partitionKey,
        public FailureOrigin $origin,
        public string $category,
        public string $message,
        public array $details,
        public DateTimeImmutable $occurredAt,
        public ?DateTimeImmutable $resolvedAt = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'attempt_id' => $this->attemptId,
            'partition_key' => $this->partitionKey,
            'origin' => $this->origin->value,
            'category' => $this->category,
            'message' => $this->message,
            'details' => $this->details,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
            'resolved_at' => $this->resolvedAt?->format(DATE_ATOM),
        ];
    }
}
