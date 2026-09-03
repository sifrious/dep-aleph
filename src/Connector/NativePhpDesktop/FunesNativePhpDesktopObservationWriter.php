<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\NativePhpDesktop;

use InvalidArgumentException;
use Sifrious\Aleph\Envelope\ArtifactReference;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ExtensionMetadata;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;

final readonly class FunesNativePhpDesktopObservationWriter implements NativePhpDesktopObservationWriter
{
    public function __construct(private EnvelopeSubmitter $submitter) {}

    public function write(NativePhpDesktopFreeformSubmission $submission, string $attemptId): string
    {
        $outcome = $this->submitter->submit(new ObservationEnvelope(
            sourceReference: $submission->sourceReference,
            sourceName: 'nativephp-desktop',
            resourceReference: $submission->artifactReference,
            observedAt: new \DateTimeImmutable,
            payload: $submission->body,
            provenance: new Provenance('nativephp-desktop', '1.0.0', $submission->sourceInstallationId, new \DateTimeImmutable, $submission->runId, [
                'artifact_reference' => $submission->artifactReference,
            ]),
            contentType: 'text/plain; charset=utf-8',
            artifacts: [
                new ArtifactReference(
                    reference: $submission->artifactReference.'#body',
                    relationship: 'primary',
                    mediaType: 'text/plain; charset=utf-8',
                    metadata: [
                        'bytes' => $submission->bytes,
                        'sha256' => $submission->sha256,
                    ],
                ),
            ],
            extensions: [
                new ExtensionMetadata('nativephp.desktop.freeform', 1, [
                    'sha256' => $submission->sha256,
                    'bytes' => $submission->bytes,
                ]),
            ],
        ), $attemptId);
        $accepted = $outcome->acceptedId();

        if (! $outcome->isAuthoritative() || $accepted === null) {
            throw new InvalidArgumentException('Funes did not accept the NativePHP Desktop freeform artifact.');
        }

        return $accepted;
    }
}
