<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Podcast;

use InvalidArgumentException;
use Sifrious\Aleph\Envelope\ArtifactReference;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ExtensionMetadata;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;

final readonly class FunesPodcastObservationWriter implements PodcastObservationWriter
{
    public function __construct(private EnvelopeSubmitter $submitter) {}

    public function write(PodcastArtifactSubmission $submission, string $attemptId): string
    {
        $outcome = $this->submitter->submit(new ObservationEnvelope(
            sourceReference: $submission->sourceReference,
            sourceName: 'podcast',
            resourceReference: $submission->episodeIdentity,
            observedAt: new \DateTimeImmutable,
            payload: $submission->contents,
            provenance: new Provenance('podcast', '1.0.0', $submission->sourceInstallationId, new \DateTimeImmutable, $submission->runId, [
                'episode_identity' => $submission->episodeIdentity,
                'enclosure_url' => $submission->enclosureUrl,
            ]),
            contentType: $submission->mediaType,
            artifacts: [
                new ArtifactReference(
                    reference: $submission->enclosureUrl.'#enclosure',
                    relationship: 'primary',
                    mediaType: $submission->mediaType,
                    metadata: [
                        'bytes' => $submission->bytes,
                        'sha256' => $submission->checksum,
                    ],
                ),
            ],
            extensions: [
                new ExtensionMetadata('podcast.episode', 1, [
                    'episode_identity' => $submission->episodeIdentity,
                    'enclosure_url' => $submission->enclosureUrl,
                    'metadata' => $submission->episodeMetadata,
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
            throw new InvalidArgumentException('Funes did not accept the podcast enclosure artifact.');
        }

        return $accepted;
    }
}
