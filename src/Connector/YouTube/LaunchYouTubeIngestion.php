<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\YouTube;

use DateTimeImmutable;
use InvalidArgumentException;
use Sifrious\Aleph\Connector\Capability;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\Contracts\DownloadsArtifacts;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;
use Sifrious\Aleph\Envelope\ArtifactReference;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ExtensionMetadata;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\LaunchIngestion;
use Sifrious\Aleph\Ingestion\LaunchIngestionRequest;
use Sifrious\Aleph\Ingestion\RunFailure;
use Throwable;

final readonly class LaunchYouTubeIngestion
{
    public function __construct(
        private LaunchIngestion $launcher,
        private IngestionRuns $runs,
        private ConnectorRegistry $connectors,
        private EnvelopeSubmitter $submitter,
    ) {}

    public function launch(LaunchYouTubeIngestionRequest $request): LaunchYouTubeIngestionResult
    {
        $canonical = YouTubeCanonicalUrl::from($request->url);
        $launch = $this->launcher->launch(new LaunchIngestionRequest(
            sourceInstallationId: $request->sourceInstallationId,
            sourceReference: $request->sourceReference,
            capability: Capability::DownloadsArtifacts,
            parameters: ['url' => $canonical->value],
            idempotencyKey: $canonical->value,
            authorization: $request->authorization,
        ));
        $run = $launch->run;

        if ($launch->replayed) {
            return new LaunchYouTubeIngestionResult($run->id, true, $run->acceptedReferences);
        }

        $existing = $this->runs->find($run->id);

        if ($existing !== null && $existing->acceptedReferences !== []) {
            return new LaunchYouTubeIngestionResult($existing->id, false, $existing->acceptedReferences);
        }

        if ($existing !== null && $this->runs->activeAttempt($existing) !== null) {
            return new LaunchYouTubeIngestionResult($existing->id, false, $existing->acceptedReferences);
        }

        $attempt = $this->runs->beginAttempt($run);
        $capturedAt = new DateTimeImmutable;

        try {
            $connector = $this->connectors->get($run->connectorId ?? '');

            if (! $connector instanceof DownloadsArtifacts) {
                throw new InvalidArgumentException('The run connector does not support artifact downloads.');
            }

            $artifact = $connector->downloadArtifact(new ArtifactRequest(
                sourceReference: $request->sourceReference,
                artifactReference: $canonical->value,
            ));
            $videoChecksum = hash('sha256', $artifact->contents);
            $videoBytes = strlen($artifact->contents);
            $transcript = is_array($artifact->metadata['transcript'] ?? null)
                ? $artifact->metadata['transcript']
                : null;
            $transcriptReference = $transcript === null ? null : new ArtifactReference(
                reference: $artifact->reference.'#transcript',
                relationship: 'transcript',
                mediaType: is_string($transcript['media_type'] ?? null) ? $transcript['media_type'] : null,
                metadata: array_filter([
                    'language' => $transcript['language'] ?? null,
                    'bytes' => $transcript['bytes'] ?? null,
                    'sha256' => $transcript['sha256'] ?? null,
                ], static fn (mixed $value): bool => $value !== null),
            );
            $outcome = $this->submitter->submit(new ObservationEnvelope(
                sourceReference: $request->sourceReference,
                sourceName: 'youtube',
                resourceReference: $artifact->reference,
                observedAt: $capturedAt,
                payload: $artifact->contents,
                provenance: new Provenance('youtube', '1.0.0', $request->sourceInstallationId, $capturedAt, $run->id, [
                    'canonical_url' => $canonical->value,
                    'download_strategy' => 'best-practical-media',
                ]),
                contentType: $artifact->mediaType,
                artifacts: array_values(array_filter([
                    new ArtifactReference(
                        reference: $artifact->reference.'#media',
                        relationship: 'primary',
                        mediaType: $artifact->mediaType,
                        metadata: [
                            'bytes' => $videoBytes,
                            'sha256' => $videoChecksum,
                        ],
                    ),
                    $transcriptReference,
                ])),
                extensions: [
                    new ExtensionMetadata('youtube.video', 1, [
                        'canonical_url' => $canonical->value,
                        'metadata' => is_array($artifact->metadata['video'] ?? null) ? $artifact->metadata['video'] : [],
                        'checksum' => ['algorithm' => 'sha256', 'value' => $videoChecksum, 'bytes' => $videoBytes],
                        'transcript' => $transcript,
                    ]),
                ],
            ), $attempt->id);
            $accepted = $outcome->acceptedId();

            if (! $outcome->isAuthoritative() || $accepted === null) {
                throw new InvalidArgumentException('Funes did not accept the YouTube artifact.');
            }

            $this->runs->succeedAttempt(
                $run,
                $attempt,
                ['artifacts' => 1, 'accepted' => 1],
                [$accepted],
            );
        } catch (Throwable $failure) {
            $this->runs->failAttempt(
                $run,
                $attempt,
                new RunFailure('youtube_ingestion', $failure->getMessage(), true, ['failure' => $failure::class]),
            );

            throw $failure;
        }

        $fresh = $this->runs->find($run->id) ?? $run;

        return new LaunchYouTubeIngestionResult($fresh->id, false, $fresh->acceptedReferences);
    }
}
