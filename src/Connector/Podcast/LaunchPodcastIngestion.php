<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Podcast;

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

final readonly class LaunchPodcastIngestion
{
    public function __construct(
        private LaunchIngestion $launcher,
        private IngestionRuns $runs,
        private ConnectorRegistry $connectors,
        private PodcastEpisodeResolver $resolver,
        private PodcastObservationWriter $writer,
    ) {}

    public function launch(LaunchPodcastIngestionRequest $request): LaunchPodcastIngestionResult
    {
        $resolved = $this->resolver->resolve($request->reference);

        if (trim($resolved->episodeIdentity) === '') {
            throw new InvalidArgumentException('Podcast episode resolution must return a canonical episode identity.');
        }

        if (trim($resolved->enclosureUrl) === '') {
            throw new UnfetchablePodcastEpisode('Podcast episode resolution did not provide a fetchable enclosure URL.');
        }

        $launch = $this->launcher->launch(new LaunchIngestionRequest(
            sourceInstallationId: $request->sourceInstallationId,
            sourceReference: $request->sourceReference,
            capability: Capability::DownloadsArtifacts,
            parameters: [
                'input_reference' => $request->reference,
                'episode_identity' => $resolved->episodeIdentity,
                'enclosure_url' => $resolved->enclosureUrl,
            ],
            idempotencyKey: $resolved->episodeIdentity,
            authorization: $request->authorization,
        ));
        $run = $launch->run;

        if ($launch->replayed) {
            return new LaunchPodcastIngestionResult(
                $run->id,
                true,
                $resolved->episodeIdentity,
                $resolved->enclosureUrl,
                $run->acceptedReferences,
            );
        }

        $existing = $this->runs->find($run->id);

        if ($existing !== null && $existing->acceptedReferences !== []) {
            return new LaunchPodcastIngestionResult(
                $existing->id,
                false,
                $resolved->episodeIdentity,
                $resolved->enclosureUrl,
                $existing->acceptedReferences,
            );
        }

        if ($existing !== null && $this->runs->activeAttempt($existing) !== null) {
            return new LaunchPodcastIngestionResult(
                $existing->id,
                false,
                $resolved->episodeIdentity,
                $resolved->enclosureUrl,
                $existing->acceptedReferences,
            );
        }

        $attempt = $this->runs->beginAttempt($run);

        try {
            $connector = $this->connectors->get($run->connectorId ?? '');

            if (! $connector instanceof DownloadsArtifacts) {
                throw new InvalidArgumentException('The run connector does not support artifact downloads.');
            }

            $artifact = $connector->downloadArtifact(new ArtifactRequest(
                sourceReference: $request->sourceReference,
                artifactReference: $resolved->enclosureUrl,
                parameters: ['episode_identity' => $resolved->episodeIdentity],
            ));
            $checksum = hash('sha256', $artifact->contents);
            $accepted = $this->writer->write(new PodcastArtifactSubmission(
                sourceReference: $request->sourceReference,
                sourceInstallationId: $request->sourceInstallationId,
                runId: $run->id,
                episodeIdentity: $resolved->episodeIdentity,
                enclosureUrl: $resolved->enclosureUrl,
                mediaType: $artifact->mediaType,
                contents: $artifact->contents,
                checksum: $checksum,
                bytes: strlen($artifact->contents),
                episodeMetadata: array_merge($resolved->metadata, $artifact->metadata),
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
                new RunFailure(
                    'podcast_ingestion',
                    $failure->getMessage(),
                    ! $failure instanceof InvalidArgumentException && ! $failure instanceof UnfetchablePodcastEpisode,
                    ['failure' => $failure::class],
                ),
            );

            throw $failure;
        }

        $fresh = $this->runs->find($run->id) ?? $run;

        return new LaunchPodcastIngestionResult(
            $fresh->id,
            false,
            $resolved->episodeIdentity,
            $resolved->enclosureUrl,
            $fresh->acceptedReferences,
        );
    }
}
