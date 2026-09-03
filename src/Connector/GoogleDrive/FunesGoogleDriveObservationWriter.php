<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GoogleDrive;

use InvalidArgumentException;
use Sifrious\Aleph\Envelope\ArtifactReference;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ExtensionMetadata;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;

final readonly class FunesGoogleDriveObservationWriter implements GoogleDriveObservationWriter
{
    public function __construct(private EnvelopeSubmitter $submitter) {}

    public function write(GoogleDriveArtifactSubmission $submission, string $attemptId): string
    {
        $outcome = $this->submitter->submit(new ObservationEnvelope(
            sourceReference: $submission->sourceReference,
            sourceName: 'google-drive',
            resourceReference: $submission->artifactReference,
            observedAt: new \DateTimeImmutable,
            payload: $submission->contents,
            provenance: new Provenance(
                'google-drive',
                '1.0.0',
                $submission->sourceInstallationId,
                new \DateTimeImmutable,
                $submission->runId,
                [
                    'file_id' => $submission->fileId,
                    'revision_id' => $submission->revisionId,
                    'source_mime_type' => $submission->sourceMimeType,
                    'native_google_format' => $submission->nativeGoogleFormat,
                ],
            ),
            contentType: $submission->mediaType,
            artifacts: [
                new ArtifactReference(
                    reference: $submission->artifactReference.'#interchange',
                    relationship: 'primary',
                    mediaType: $submission->mediaType,
                    metadata: [
                        'bytes' => $submission->bytes,
                        'sha256' => $submission->checksum,
                        'filename' => $submission->filename,
                        'file_id' => $submission->fileId,
                        'revision_id' => $submission->revisionId,
                    ],
                ),
            ],
            extensions: [
                new ExtensionMetadata('google_drive.file', 1, [
                    'file_id' => $submission->fileId,
                    'revision_id' => $submission->revisionId,
                    'source_mime_type' => $submission->sourceMimeType,
                    'filename' => $submission->filename,
                    'native_google_format' => $submission->nativeGoogleFormat,
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
            throw new InvalidArgumentException('Funes did not accept the Google Drive interchange artifact.');
        }

        return $accepted;
    }
}
