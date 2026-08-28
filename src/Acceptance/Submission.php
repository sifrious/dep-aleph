<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Acceptance;

use DateTimeImmutable;

final readonly class Submission
{
    public function __construct(
        public string $id,
        public ?string $attemptId,
        public string $idempotencyKey,
        public string $payloadHash,
        public SubmissionStatus $status,
        public ?string $acceptedType,
        public ?string $acceptedId,
        public ?string $error,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $completedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'attempt_id' => $this->attemptId,
            'idempotency_key' => $this->idempotencyKey,
            'payload_hash' => $this->payloadHash,
            'status' => $this->status->value,
            'accepted_type' => $this->acceptedType,
            'accepted_id' => $this->acceptedId,
            'error' => $this->error,
        ];
    }
}
