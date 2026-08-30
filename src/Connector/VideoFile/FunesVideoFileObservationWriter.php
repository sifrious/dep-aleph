<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\VideoFile;

use InvalidArgumentException;
use Sifrious\Aleph\Envelope\ArtifactReference;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ExtensionMetadata;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;

final readonly class FunesVideoFileObservationWriter implements VideoFileObservationWriter
{
    public function __construct(private EnvelopeSubmitter $submitter) {}

    public function write(VideoFileArtifactSubmission $submission, string $attemptId): string
    {
        $outcome = $this->submitter->submit(new ObservationEnvelope(
            sourceReference: $submission->sourceReference,
            sourceName: 'local-video-file',
            resourceReference: $submission->artifactReference,
            observedAt: new \DateTimeImmutable,
            payload: $submission->contents,
            provenance: new Provenance('video-file', '1.0.0', $submission->sourceInstallationId, new \DateTimeImmutable, $submission->runId, [
                'artifact_reference' => $submission->artifactReference,
            ]),
            contentType: $submission->mediaType,
            artifacts: [
                new ArtifactReference(
                    reference: $submission->artifactReference.'#media',
                    relationship: 'primary',
                    mediaType: $submission->mediaType,
                    metadata: [
                        'bytes' => $submission->bytes,
                        'sha256' => $submission->checksum,
                    ],
                ),
            ],
            extensions: [
                new ExtensionMetadata('video.file', 1, [
                    'artifact_reference' => $submission->artifactReference,
                    'metadata' => $submission->metadata,
                    'checksum' => [
                        'algorithm' => 'sha256',
                        'value' => $submission->checksum,
                        'bytes' => $submission->bytes,
                    ],
                ]),
            ],
        ), $attemptId);
        $accepted = $outcome->acceptedId();

        if (! $outcome->isAuthoritative() || $accepted === null) {
            throw new InvalidArgumentException('Funes did not accept the local video artifact.');
        }

        return $accepted;
    }
}
