<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\YouTube;

use InvalidArgumentException;
use Sifrious\Aleph\Connector\Capability;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\Contracts\DownloadsArtifacts;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;
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
        private YouTubeObservationWriter $writer,
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
            $accepted = $this->writer->write(new YouTubeArtifactSubmission(
                sourceReference: $request->sourceReference,
                sourceInstallationId: $request->sourceInstallationId,
                runId: $run->id,
                canonicalUrl: $canonical->value,
                mediaType: $artifact->mediaType,
                contents: $artifact->contents,
                checksum: $videoChecksum,
                bytes: $videoBytes,
                videoMetadata: is_array($artifact->metadata['video'] ?? null) ? $artifact->metadata['video'] : [],
                transcript: $transcript,
            ), $attempt->id);

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
