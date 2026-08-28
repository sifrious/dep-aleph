<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Envelope;

use Sifrious\Aleph\Acceptance\AcceptanceClient;
use Sifrious\Aleph\Acceptance\AcceptanceOutcomeRecord;
use Sifrious\Aleph\Normalization\CandidateEnvelope;
use Sifrious\Funes\Value\ObservationDraft;

final readonly class EnvelopeSubmitter
{
    public function __construct(
        private AcceptanceClient $acceptance,
        private EnvelopeDrafter $drafter,
    ) {}

    public function submit(ObservationEnvelope $envelope, ?string $attemptId = null): AcceptanceOutcomeRecord
    {
        return $this->acceptance->submit(CandidateEnvelope::forEnvelope($envelope), $attemptId);
    }

    public function draft(ObservationEnvelope $envelope): ObservationDraft
    {
        return $this->drafter->draft($envelope);
    }
}
