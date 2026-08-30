<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\VideoFile;

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

final readonly class LaunchLocalVideoIngestion
{
    public function __construct(
        private LaunchIngestion $launcher,
        private IngestionRuns $runs,
        private ConnectorRegistry $connectors,
        private VideoFileObservationWriter $writer,
    ) {}

    public function launch(LaunchLocalVideoIngestionRequest $request): LaunchLocalVideoIngestionResult
    {
        $prepared = $this->prepare($request);
        $launch = $this->launcher->launch(new LaunchIngestionRequest(
            sourceInstallationId: $request->sourceInstallationId,
            sourceReference: $request->sourceReference,
            capability: Capability::DownloadsArtifacts,
            parameters: $prepared['run_parameters'],
            idempotencyKey: 'sha256:'.$prepared['checksum'],
            authorization: $request->authorization,
        ));
        $run = $launch->run;

        if ($launch->replayed) {
            return new LaunchLocalVideoIngestionResult($run->id, true, $run->acceptedReferences);
        }

        $existing = $this->runs->find($run->id);

        if ($existing !== null && $existing->acceptedReferences !== []) {
            return new LaunchLocalVideoIngestionResult($existing->id, false, $existing->acceptedReferences);
        }

        if ($existing !== null && $this->runs->activeAttempt($existing) !== null) {
            return new LaunchLocalVideoIngestionResult($existing->id, false, $existing->acceptedReferences);
        }

        $attempt = $this->runs->beginAttempt($run);
        try {
            $connector = $this->connectors->get($run->connectorId ?? '');

            if (! $connector instanceof DownloadsArtifacts) {
                throw new InvalidArgumentException('The run connector does not support artifact downloads.');
            }

            $artifact = $connector->downloadArtifact(new ArtifactRequest(
                sourceReference: $request->sourceReference,
                artifactReference: $prepared['artifact_reference'],
                parameters: $prepared['connector_parameters'],
            ));
            $videoChecksum = hash('sha256', $artifact->contents);

            if ($videoChecksum !== $prepared['checksum']) {
                throw new InvalidArgumentException('Video content hash changed between launch and artifact download.');
            }

            $accepted = $this->writer->write(new VideoFileArtifactSubmission(
                sourceReference: $request->sourceReference,
                sourceInstallationId: $request->sourceInstallationId,
                runId: $run->id,
                artifactReference: $prepared['artifact_reference'],
                mediaType: $artifact->mediaType,
                contents: $artifact->contents,
                checksum: $videoChecksum,
                bytes: strlen($artifact->contents),
                metadata: is_array($artifact->metadata) ? $artifact->metadata : [],
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
                new RunFailure('local_video_ingestion', $failure->getMessage(), true, ['failure' => $failure::class]),
            );

            throw $failure;
        }

        $fresh = $this->runs->find($run->id) ?? $run;

        return new LaunchLocalVideoIngestionResult($fresh->id, false, $fresh->acceptedReferences);
    }

    /**
     * @return array{
     *     artifact_reference: string,
     *     checksum: string,
     *     run_parameters: array<string, mixed>,
     *     connector_parameters: array<string, mixed>
     * }
     */
    private function prepare(LaunchLocalVideoIngestionRequest $request): array
    {
        if ($request->path !== null && $request->file !== null) {
            throw new InvalidArgumentException('Local video ingestion accepts either a path or a file payload, not both.');
        }

        if ($request->path !== null) {
            $raw = trim($request->path);
            $path = $raw === '' ? false : realpath($raw);

            if ($path === false || ! is_file($path) || ! is_readable($path)) {
                throw new InvalidArgumentException('Local video ingestion path input must point to a readable file.');
            }

            $checksum = hash_file('sha256', $path);

            if (! is_string($checksum)) {
                throw new InvalidArgumentException('Local video ingestion could not hash the supplied path input.');
            }

            return [
                'artifact_reference' => 'file://'.$path,
                'checksum' => $checksum,
                'run_parameters' => array_filter([
                    'input' => 'path',
                    'path' => $path,
                    'name' => basename($path),
                    'sha256' => $checksum,
                ], static fn (mixed $value): bool => $value !== null),
                'connector_parameters' => array_filter([
                    'input' => 'path',
                    'path' => $path,
                ], static fn (mixed $value): bool => $value !== null),
            ];
        }

        $file = $request->file;

        if ($file === null) {
            throw new InvalidArgumentException('Local video ingestion requires either a local path or a file payload.');
        }

        if (trim($file->name) === '') {
            throw new InvalidArgumentException('Local video file payload requires a stable file name.');
        }

        $checksum = hash('sha256', $file->contents);
        $mediaType = $file->mediaType;
        $artifactReference = 'memory://'.$file->name.'#sha256:'.$checksum;

        return [
            'artifact_reference' => $artifactReference,
            'checksum' => $checksum,
            'run_parameters' => array_filter([
                'input' => 'file',
                'name' => $file->name,
                'sha256' => $checksum,
                'media_type' => $mediaType,
            ], static fn (mixed $value): bool => $value !== null),
            'connector_parameters' => array_filter([
                'input' => 'file',
                'name' => $file->name,
                'contents_base64' => base64_encode($file->contents),
                'media_type' => $mediaType,
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }
}
