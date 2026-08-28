<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Normalization;

final readonly class NormalizationResult
{
    public function __construct(
        public NormalizationStatus $status,
        public CandidateEnvelopes $candidates,
        public NormalizationAttempt $attempt,
        /** @var list<string> */
        public array $violations = [],
    ) {}

    public function successful(): bool
    {
        return ! $this->status->isFailure();
    }
}
