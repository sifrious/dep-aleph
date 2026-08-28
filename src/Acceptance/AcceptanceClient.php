<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Acceptance;

use Sifrious\Aleph\Envelope\EnvelopeDrafter;
use Sifrious\Aleph\Normalization\CandidateEnvelope;
use Sifrious\Aleph\Normalization\CandidateEnvelopes;
use Sifrious\Funes\Acceptance\AcceptanceGateway;
use Sifrious\Funes\Acceptance\AcceptanceOutcome;
use Sifrious\Funes\Acceptance\Submission as FunesSubmission;
use Throwable;

final readonly class AcceptanceClient
{
    public function __construct(
        private AcceptanceGateway $gateway,
        private Submissions $submissions,
        private EnvelopeDrafter $drafter,
    ) {}

    public function submit(CandidateEnvelope $candidate, ?string $attemptId = null): AcceptanceOutcomeRecord
    {
        $key = (string) IdempotencyKey::for($candidate);
        $envelope = $candidate->toObservationEnvelope();
        $draft = $this->drafter->draft($envelope);
        $payloadHash = hash('sha256', $draft->payload);

        $submission = $this->submissions->open($key, $payloadHash, $attemptId);

        try {
            $result = $this->gateway->accept(new FunesSubmission($key, $draft, $envelope->occurredAt));
        } catch (Throwable $failure) {
            return new AcceptanceOutcomeRecord($candidate, $this->submissions->settle(
                $submission,
                SubmissionStatus::TransportFailed,
                error: $failure::class.': '.$failure->getMessage(),
            ));
        }

        return new AcceptanceOutcomeRecord(
            $candidate,
            $this->submissions->settle(
                $submission,
                $this->statusFor($result->outcome),
                $result->acceptedType,
                $result->acceptedId,
                $result->errors === [] ? null : implode('; ', $result->errors),
            ),
            $result->disposition,
        );
    }

    /**
     * @return list<AcceptanceOutcomeRecord>
     */
    public function submitAll(CandidateEnvelopes $candidates, ?string $attemptId = null): array
    {
        $records = [];

        foreach ($candidates as $candidate) {
            $records[] = $this->submit($candidate, $attemptId);
        }

        return $records;
    }

    private function statusFor(AcceptanceOutcome $outcome): SubmissionStatus
    {
        return match ($outcome) {
            AcceptanceOutcome::Accepted => SubmissionStatus::Accepted,
            AcceptanceOutcome::Replayed => SubmissionStatus::Replayed,
            AcceptanceOutcome::Rejected => SubmissionStatus::Rejected,
            AcceptanceOutcome::InFlight => SubmissionStatus::InFlight,
        };
    }
}
