<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\ScoreTab;

use InvalidArgumentException;
use Sifrious\Aleph\Envelope\ArtifactReference;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ExtensionMetadata;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;

final readonly class FunesScoreTabObservationWriter implements ScoreTabObservationWriter
{
    public function __construct(private EnvelopeSubmitter $submitter) {}

    public function write(ScoreTabArtifactSubmission $submission, string $attemptId): string
    {
        $outcome = $this->submitter->submit(new ObservationEnvelope(
            sourceReference: $submission->sourceReference,
            sourceName: 'score-tab',
            resourceReference: $submission->artifactReference,
            observedAt: new \DateTimeImmutable,
            payload: $submission->contents,
            provenance: new Provenance('score-tab', '1.0.0', $submission->sourceInstallationId, new \DateTimeImmutable, $submission->runId, [
                'artifact_reference' => $submission->artifactReference,
            ]),
            contentType: $submission->mediaType,
            artifacts: [
                new ArtifactReference(
                    reference: $submission->artifactReference.'#score-tab',
                    relationship: 'primary',
                    mediaType: $submission->mediaType,
                    metadata: [
                        'bytes' => $submission->bytes,
                        'sha256' => $submission->checksum,
                    ],
                ),
            ],
            extensions: [
                new ExtensionMetadata('score_tab.file', 1, [
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
            throw new InvalidArgumentException('Funes did not accept the score/tab artifact.');
        }

        return $accepted;
    }
}
