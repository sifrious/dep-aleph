<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\YouTube;

use InvalidArgumentException;
use Sifrious\Aleph\Envelope\ArtifactReference;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ExtensionMetadata;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;

final readonly class FunesYouTubeObservationWriter implements YouTubeObservationWriter
{
    public function __construct(private EnvelopeSubmitter $submitter) {}

    public function write(YouTubeArtifactSubmission $submission, string $attemptId): string
    {
        $transcriptReference = $submission->transcript === null ? null : new ArtifactReference(
            reference: $submission->canonicalUrl.'#transcript',
            relationship: 'transcript',
            mediaType: is_string($submission->transcript['media_type'] ?? null)
                ? $submission->transcript['media_type']
                : null,
            metadata: array_filter([
                'language' => $submission->transcript['language'] ?? null,
                'bytes' => $submission->transcript['bytes'] ?? null,
                'sha256' => $submission->transcript['sha256'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
        );
        $outcome = $this->submitter->submit(new ObservationEnvelope(
            sourceReference: $submission->sourceReference,
            sourceName: 'youtube',
            resourceReference: $submission->canonicalUrl,
            observedAt: new \DateTimeImmutable,
            payload: $submission->contents,
            provenance: new Provenance('youtube', '1.0.0', $submission->sourceInstallationId, new \DateTimeImmutable, $submission->runId, [
                'canonical_url' => $submission->canonicalUrl,
                'download_strategy' => 'best-practical-media',
            ]),
            contentType: $submission->mediaType,
            artifacts: array_filter([
                new ArtifactReference(
                    reference: $submission->canonicalUrl.'#media',
                    relationship: 'primary',
                    mediaType: $submission->mediaType,
                    metadata: [
                        'bytes' => $submission->bytes,
                        'sha256' => $submission->checksum,
                    ],
                ),
                $transcriptReference,
            ]),
            extensions: [
                new ExtensionMetadata('youtube.video', 1, [
                    'canonical_url' => $submission->canonicalUrl,
                    'metadata' => $submission->videoMetadata,
                    'checksum' => [
                        'algorithm' => 'sha256',
                        'value' => $submission->checksum,
                        'bytes' => $submission->bytes,
                    ],
                    'transcript' => $submission->transcript,
                ]),
            ],
        ), $attemptId);
        $accepted = $outcome->acceptedId();

        if (! $outcome->isAuthoritative() || $accepted === null) {
            throw new InvalidArgumentException('Funes did not accept the YouTube artifact.');
        }

        return $accepted;
    }
}
