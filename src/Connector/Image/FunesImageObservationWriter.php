<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Image;

use InvalidArgumentException;
use Sifrious\Aleph\Envelope\ArtifactReference;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ExtensionMetadata;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;

final readonly class FunesImageObservationWriter implements ImageObservationWriter
{
    public function __construct(private EnvelopeSubmitter $submitter) {}

    public function write(ImageArtifactSubmission $submission, string $attemptId): string
    {
        $image = $submission->image->toArray();
        $outcome = $this->submitter->submit(new ObservationEnvelope(
            sourceReference: $submission->sourceReference,
            sourceName: 'image',
            resourceReference: $submission->artifactReference,
            observedAt: new \DateTimeImmutable,
            payload: $submission->contents,
            provenance: new Provenance('image', '1.0.0', $submission->sourceInstallationId, new \DateTimeImmutable, $submission->runId, [
                'artifact_reference' => $submission->artifactReference,
            ]),
            contentType: $submission->mediaType,
            artifacts: [
                new ArtifactReference(
                    reference: $submission->artifactReference.'#image',
                    relationship: 'primary',
                    mediaType: $submission->mediaType,
                    metadata: [
                        'bytes' => $submission->bytes,
                        'sha256' => $submission->checksum,
                        'width' => $submission->image->width,
                        'height' => $submission->image->height,
                        'color_space' => $submission->image->colorSpace,
                        'exif_presence' => $submission->image->exifPresence->value,
                    ],
                ),
            ],
            extensions: [
                new ExtensionMetadata('image.file', 1, [
                    'artifact_reference' => $submission->artifactReference,
                    'metadata' => $submission->metadata,
                    'image' => $image,
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
            throw new InvalidArgumentException('Funes did not accept the image artifact.');
        }

        return $accepted;
    }
}
