<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Normalization;

use DateTimeImmutable;

final readonly class NormalizationAttempt
{
    public function __construct(
        public string $id,
        public ?string $ingestionAttemptId,
        public NormalizerIdentity $normalizer,
        public CandidateSchema $schema,
        public string $inputHash,
        public string $sourceReference,
        public NormalizationStatus $status,
        public int $candidateCount,
        public bool $cached,
        public ?string $errorCode,
        public ?string $error,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $completedAt,
        public int $durationMs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'ingestion_attempt_id' => $this->ingestionAttemptId,
            'normalizer' => $this->normalizer->reference(),
            'candidate_schema' => $this->schema->reference(),
            'input_hash' => $this->inputHash,
            'source' => $this->sourceReference,
            'status' => $this->status->value,
            'candidate_count' => $this->candidateCount,
            'cached' => $this->cached,
            'error_code' => $this->errorCode,
            'error' => $this->error,
            'duration_ms' => $this->durationMs,
        ];
    }
}
