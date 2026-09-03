<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Midi;

use InvalidArgumentException;
use Sifrious\Aleph\Envelope\ArtifactReference;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ExtensionMetadata;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;

final readonly class FunesMidiObservationWriter implements MidiObservationWriter
{
    public function __construct(private EnvelopeSubmitter $submitter) {}

    public function write(MidiArtifactSubmission $submission, string $attemptId): string
    {
        $outcome = $this->submitter->submit(new ObservationEnvelope(
            sourceReference: $submission->sourceReference,
            sourceName: 'midi',
            resourceReference: $submission->artifactReference,
            observedAt: new \DateTimeImmutable,
            payload: $submission->contents,
            provenance: new Provenance(
                'midi',
                '1.0.0',
                $submission->sourceInstallationId,
                new \DateTimeImmutable,
                $submission->runId,
                [
                    'artifact_reference' => $submission->artifactReference,
                    'parser' => MidiParseResult::PARSER_NAME,
                    'parser_version' => MidiParseResult::PARSER_VERSION,
                    'smf_format' => $submission->parse->format,
                ],
            ),
            contentType: $submission->mediaType,
            artifacts: [
                new ArtifactReference(
                    reference: $submission->artifactReference.'#midi',
                    relationship: 'primary',
                    mediaType: $submission->mediaType,
                    metadata: [
                        'bytes' => $submission->bytes,
                        'sha256' => $submission->checksum,
                        'smf_format' => $submission->parse->format,
                        'parser' => MidiParseResult::PARSER_NAME,
                    ],
                ),
            ],
            extensions: [
                new ExtensionMetadata('midi.file', 1, [
                    'artifact_reference' => $submission->artifactReference,
                    'metadata' => $submission->metadata,
                    'parser' => MidiParseResult::PARSER_NAME,
                    'smf_format' => $submission->parse->format,
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
            throw new InvalidArgumentException('Funes did not accept the MIDI artifact.');
        }

        return $accepted;
    }
}
