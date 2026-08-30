<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\SpokenSound;

use InvalidArgumentException;
use Sifrious\Aleph\Envelope\ArtifactReference;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ExtensionMetadata;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;

final readonly class FunesSpokenSoundObservationWriter implements SpokenSoundObservationWriter
{
    public function __construct(private EnvelopeSubmitter $submitter) {}

    public function write(SpokenSoundArtifactSubmission $submission, string $attemptId): string
    {
        $outcome = $this->submitter->submit(new ObservationEnvelope(
            sourceReference: $submission->sourceReference,
            sourceName: 'spoken-sound',
            resourceReference: $submission->artifactReference,
            observedAt: new \DateTimeImmutable,
            payload: $submission->contents,
            provenance: new Provenance(
                'spoken-sound',
                '1.0.0',
                $submission->sourceInstallationId,
                new \DateTimeImmutable,
                $submission->runId,
            ),
            contentType: $submission->mediaType,
            artifacts: [
                new ArtifactReference(
                    reference: $submission->artifactReference.'#audio',
                    relationship: 'primary',
                    mediaType: $submission->mediaType,
                    metadata: array_filter([
                        'bytes' => $submission->bytes,
                        'sha256' => $submission->checksum,
                        'duration_seconds' => $submission->durationSeconds,
                        'container' => $submission->containerMetadata,
                    ], static fn (mixed $value): bool => $value !== null && $value !== []),
                ),
            ],
            extensions: [
                new ExtensionMetadata('spoken_sound.file', 1, array_filter([
                    'reference' => $submission->artifactReference,
                    'checksum' => [
                        'algorithm' => 'sha256',
                        'value' => $submission->checksum,
                    ],
                    'bytes' => $submission->bytes,
                    'duration_seconds' => $submission->durationSeconds,
                    'container' => $submission->containerMetadata,
                ], static fn (mixed $value): bool => $value !== null && $value !== [])),
            ],
        ), $attemptId);
        $accepted = $outcome->acceptedId();

        if (! $outcome->isAuthoritative() || $accepted === null) {
            throw new InvalidArgumentException('Funes did not accept the spoken sound artifact.');
        }

        return $accepted;
    }
}
