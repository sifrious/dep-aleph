<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\VideoFile;

use DateTimeImmutable;
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
        return $this->writeEnvelopeDocument(
            VideoFileEnvelopeDocument::fromSubmission($submission, $submission->language),
            $attemptId,
        );
    }

    public function writeEnvelopeDocument(array $document, string $attemptId): string
    {
        $payload = base64_decode((string) ($document['payload_base64'] ?? ''), true);

        if (! is_string($payload)) {
            throw new InvalidArgumentException('Video envelope document requires payload_base64.');
        }

        $provenance = is_array($document['provenance'] ?? null) ? $document['provenance'] : [];
        $details = is_array($provenance['details'] ?? null) ? $provenance['details'] : [];
        $artifacts = [];

        foreach (($document['artifacts'] ?? []) as $artifact) {
            if (! is_array($artifact)) {
                continue;
            }

            $artifacts[] = new ArtifactReference(
                reference: (string) ($artifact['reference'] ?? ''),
                relationship: (string) ($artifact['relationship'] ?? 'primary'),
                mediaType: isset($artifact['media_type']) ? (string) $artifact['media_type'] : null,
                metadata: is_array($artifact['metadata'] ?? null) ? $artifact['metadata'] : [],
            );
        }

        $extensions = [];

        foreach (($document['extensions'] ?? []) as $extension) {
            if (! is_array($extension)) {
                continue;
            }

            $extensions[] = ExtensionMetadata::fromArray($extension);
        }

        $outcome = $this->submitter->submit(new ObservationEnvelope(
            sourceReference: (string) ($document['source_reference'] ?? ''),
            sourceName: (string) ($document['source_name'] ?? VideoFileEnvelopeDocument::SOURCE_NAME),
            resourceReference: (string) ($document['resource_reference'] ?? ''),
            observedAt: new DateTimeImmutable,
            payload: $payload,
            provenance: new Provenance(
                connectorId: (string) ($provenance['connector'] ?? VideoFileEnvelopeDocument::CAPABILITY),
                connectorVersion: (string) ($provenance['connector_version'] ?? VideoFileEnvelopeDocument::CONNECTOR_VERSION),
                installationId: (string) ($provenance['installation'] ?? ''),
                capturedAt: new DateTimeImmutable,
                runId: isset($provenance['run']) ? (string) $provenance['run'] : null,
                details: $details,
            ),
            contentType: (string) ($document['content_type'] ?? 'application/octet-stream'),
            artifacts: $artifacts,
            extensions: $extensions,
        ), $attemptId);
        $accepted = $outcome->acceptedId();

        if (! $outcome->isAuthoritative() || $accepted === null) {
            throw new InvalidArgumentException('Funes did not accept the local video artifact.');
        }

        return $accepted;
    }
}
