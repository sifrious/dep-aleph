<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\ScoreTab;

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

final readonly class LaunchScoreTabIngestion
{
    public function __construct(
        private LaunchIngestion $launcher,
        private IngestionRuns $runs,
        private ConnectorRegistry $connectors,
        private ScoreTabObservationWriter $writer,
        private ScoreTabLocalModel $localModel,
        private ScoreTabDerivationRecorder $derivations,
    ) {}

    public function launch(LaunchScoreTabIngestionRequest $request): LaunchScoreTabIngestionResult
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
            return new LaunchScoreTabIngestionResult($run->id, true, $run->acceptedReferences);
        }

        $existing = $this->runs->find($run->id);

        if ($existing !== null && $existing->acceptedReferences !== []) {
            return new LaunchScoreTabIngestionResult($existing->id, false, $existing->acceptedReferences);
        }

        if ($existing !== null && $this->runs->activeAttempt($existing) !== null) {
            return new LaunchScoreTabIngestionResult($existing->id, false, $existing->acceptedReferences);
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
            $checksum = hash('sha256', $artifact->contents);

            if ($checksum !== $prepared['checksum']) {
                throw new InvalidArgumentException('Score/tab content hash changed between launch and artifact download.');
            }

            $accepted = $this->writer->write(new ScoreTabArtifactSubmission(
                sourceReference: $request->sourceReference,
                sourceInstallationId: $request->sourceInstallationId,
                runId: $run->id,
                artifactReference: $prepared['artifact_reference'],
                mediaType: $artifact->mediaType,
                contents: $artifact->contents,
                checksum: $checksum,
                bytes: strlen($artifact->contents),
                metadata: is_array($artifact->metadata) ? $artifact->metadata : [],
            ), $attempt->id);

            $this->maybeDerive(
                $accepted,
                $artifact->contents,
                $artifact->mediaType,
                is_string($artifact->metadata['name'] ?? null) ? $artifact->metadata['name'] : 'score-tab',
                $run->id,
            );

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
                new RunFailure('score_tab_ingestion', $failure->getMessage(), true, ['failure' => $failure::class]),
            );

            throw $failure;
        }

        $fresh = $this->runs->find($run->id) ?? $run;

        return new LaunchScoreTabIngestionResult($fresh->id, false, $fresh->acceptedReferences);
    }

    private function maybeDerive(
        string $observationId,
        string $contents,
        string $mediaType,
        string $name,
        string $runId,
    ): void {
        if (! $this->localModel->available()) {
            return;
        }

        try {
            $derivation = $this->localModel->derive($contents, $mediaType, $name);

            if ($derivation === null) {
                return;
            }

            $this->derivations->record($observationId, $derivation, $runId);
        } catch (Throwable) {
            // Optional free local models must never fail authoritative score/tab ingest.
        }
    }

    /**
     * @return array{
     *     artifact_reference: string,
     *     checksum: string,
     *     run_parameters: array<string, mixed>,
     *     connector_parameters: array<string, mixed>
     * }
     */
    private function prepare(LaunchScoreTabIngestionRequest $request): array
    {
        if ($request->path !== null && $request->file !== null) {
            throw new InvalidArgumentException('Score/tab ingestion accepts either a path or a file payload, not both.');
        }

        if ($request->path !== null) {
            $raw = trim($request->path);
            $path = $raw === '' ? false : realpath($raw);

            if ($path === false || ! is_file($path) || ! is_readable($path)) {
                throw new InvalidArgumentException('Score/tab path input must point to a readable file.');
            }

            $checksum = hash_file('sha256', $path);

            if (! is_string($checksum)) {
                throw new InvalidArgumentException('Score/tab ingestion could not hash the supplied path input.');
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
            throw new InvalidArgumentException('Score/tab ingestion requires either a local path or a file payload.');
        }

        if (trim($file->name) === '') {
            throw new InvalidArgumentException('Score/tab file payload requires a stable file name.');
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
