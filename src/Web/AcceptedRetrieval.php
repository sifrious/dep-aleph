<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

use DateTimeImmutable;
use Sifrious\Aleph\Acceptance\SubmissionStatus;
use Sifrious\Aleph\Extraction\ExtractionStatus;
use Sifrious\Funes\Value\AcceptedObservation;
use Sifrious\Funes\Value\ExtractionResult;
use Sifrious\Funes\Value\Observation;
use Sifrious\Funes\Value\ObservationDisposition;

final readonly class AcceptedRetrieval
{
    public function __construct(
        public string $observationId,
        public ObservationDisposition $disposition,
        public string $payloadHash,
        public int $byteSize,
        public DateTimeImmutable $observedAt,
        public DateTimeImmutable $ingestedAt,
        public string $extractor,
        public string $extractionVersion,
        public ExtractionStatus $extractionStatus,
        public ?string $extractionError,
    ) {}

    public static function of(AcceptedObservation $accepted, ExtractionResult $extraction): self
    {
        return new self(
            observationId: $accepted->observation->id,
            disposition: $accepted->disposition,
            payloadHash: $accepted->observation->payloadHash,
            byteSize: strlen($accepted->observation->payload),
            observedAt: $accepted->observation->observedAt,
            ingestedAt: $accepted->observation->ingestedAt,
            extractor: $extraction->extractor,
            extractionVersion: $extraction->version,
            extractionStatus: $extraction->succeeded() ? ExtractionStatus::Succeeded : ExtractionStatus::Failed,
            extractionError: $extraction->failure,
        );
    }

    public static function fromAccepted(
        Observation $observation,
        ?ObservationDisposition $disposition,
        SubmissionStatus $status,
        ExtractionResult $extraction,
    ): self {
        return new self(
            observationId: $observation->id,
            disposition: $disposition ?? ($status === SubmissionStatus::Replayed
                ? ObservationDisposition::Unchanged
                : ObservationDisposition::First),
            payloadHash: $observation->payloadHash,
            byteSize: strlen($observation->payload),
            observedAt: $observation->observedAt,
            ingestedAt: $observation->ingestedAt,
            extractor: $extraction->extractor,
            extractionVersion: $extraction->version,
            extractionStatus: $extraction->succeeded() ? ExtractionStatus::Succeeded : ExtractionStatus::Failed,
            extractionError: $extraction->failure,
        );
    }
}
