<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Acceptance;

use Sifrious\Aleph\Normalization\CandidateEnvelope;
use Sifrious\Funes\Value\ObservationDisposition;

final readonly class AcceptanceOutcomeRecord
{
    public function __construct(
        public CandidateEnvelope $candidate,
        public Submission $submission,
        public ?ObservationDisposition $disposition = null,
    ) {}

    public function acceptedId(): ?string
    {
        return $this->submission->acceptedId;
    }

    public function isAuthoritative(): bool
    {
        return $this->submission->status->isAuthoritative();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'candidate' => $this->candidate->describe(),
            'submission' => $this->submission->toArray(),
        ];
    }
}
