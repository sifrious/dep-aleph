<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GoogleDrive;

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

final readonly class LaunchGoogleDriveIngestion
{
    public function __construct(
        private LaunchIngestion $launcher,
        private IngestionRuns $runs,
        private ConnectorRegistry $connectors,
        private GoogleDriveFileClient $client,
        private GoogleDriveObservationWriter $writer,
        private DocumentFormatHandoff $formatHandoff,
    ) {}

    public function launch(LaunchGoogleDriveIngestionRequest $request): LaunchGoogleDriveIngestionResult
    {
        $fileId = trim($request->fileId);

        if ($fileId === '') {
            throw new InvalidArgumentException('Google Drive ingestion requires a non-empty file id.');
        }

        $meta = $this->client->metadata($fileId);
        $plan = GoogleDriveExportPlan::for($meta->mimeType, $request->preferredExtension);
        $artifactReference = GoogleDriveConnector::artifactReference($meta->fileId, $meta->revisionId);
        $idempotencyKey = 'google-drive:'.$meta->fileId.':'.$meta->revisionId;

        $launch = $this->launcher->launch(new LaunchIngestionRequest(
            sourceInstallationId: $request->sourceInstallationId,
            sourceReference: $request->sourceReference,
            capability: Capability::DownloadsArtifacts,
            parameters: array_filter([
                'file_id' => $meta->fileId,
                'revision_id' => $meta->revisionId,
                'source_mime_type' => $meta->mimeType,
                'name' => $meta->name,
                'preferred_extension' => $request->preferredExtension,
                'export_media_type' => $plan['media_type'],
                'export_extension' => $plan['extension'],
                'export' => $plan['export'],
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            idempotencyKey: $idempotencyKey,
            authorization: $request->authorization,
        ));
        $run = $launch->run;

        if ($launch->replayed) {
            return new LaunchGoogleDriveIngestionResult(
                $run->id,
                true,
                $meta->fileId,
                $meta->revisionId,
                $artifactReference,
                $plan['media_type'],
                $plan['extension'],
                $run->acceptedReferences,
                is_array($run->parameters['format_handoff'] ?? null) ? $run->parameters['format_handoff'] : [],
            );
        }

        $existing = $this->runs->find($run->id);

        if ($existing !== null && $existing->acceptedReferences !== []) {
            return new LaunchGoogleDriveIngestionResult(
                $existing->id,
                false,
                $meta->fileId,
                $meta->revisionId,
                $artifactReference,
                $plan['media_type'],
                $plan['extension'],
                $existing->acceptedReferences,
            );
        }

        if ($existing !== null && $this->runs->activeAttempt($existing) !== null) {
            return new LaunchGoogleDriveIngestionResult(
                $existing->id,
                false,
                $meta->fileId,
                $meta->revisionId,
                $artifactReference,
                $plan['media_type'],
                $plan['extension'],
                $existing->acceptedReferences,
            );
        }

        $attempt = $this->runs->beginAttempt($run);
        $exportMediaType = $plan['media_type'];
        $exportExtension = $plan['extension'];
        $revisionId = $meta->revisionId;
        $handoffPayload = [];

        try {
            $connector = $this->connectors->get($run->connectorId ?? '');

            if (! $connector instanceof DownloadsArtifacts) {
                throw new InvalidArgumentException('The run connector does not support artifact downloads.');
            }

            $artifact = $connector->downloadArtifact(new ArtifactRequest(
                sourceReference: $request->sourceReference,
                artifactReference: $artifactReference,
                parameters: array_filter([
                    'file_id' => $meta->fileId,
                    'preferred_extension' => $request->preferredExtension,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            ));

            if ($artifact->contents === '') {
                throw new GoogleDriveExportDenied(
                    sprintf(
                        'Google Drive returned empty interchange bytes for file [%s]. Missing export permission is explicit failure.',
                        $meta->fileId,
                    ),
                );
            }

            $checksum = hash('sha256', $artifact->contents);
            $filename = is_string($artifact->metadata['filename'] ?? null)
                ? (string) $artifact->metadata['filename']
                : $meta->name;
            $sourceMime = is_string($artifact->metadata['source_mime_type'] ?? null)
                ? (string) $artifact->metadata['source_mime_type']
                : $meta->mimeType;
            $revisionId = is_string($artifact->metadata['revision_id'] ?? null)
                ? (string) $artifact->metadata['revision_id']
                : $meta->revisionId;
            $exportMediaType = $artifact->mediaType;
            $exportExtension = is_string($artifact->metadata['export_extension'] ?? null)
                ? (string) $artifact->metadata['export_extension']
                : $plan['extension'];
            $native = (bool) ($artifact->metadata['native_google_format'] ?? GoogleDriveExportPlan::isNativeGoogleFormat($sourceMime));

            $accepted = $this->writer->write(new GoogleDriveArtifactSubmission(
                sourceReference: $request->sourceReference,
                sourceInstallationId: $request->sourceInstallationId,
                runId: $run->id,
                artifactReference: $artifactReference,
                fileId: $meta->fileId,
                revisionId: $revisionId,
                sourceMimeType: $sourceMime,
                mediaType: $artifact->mediaType,
                filename: $filename,
                contents: $artifact->contents,
                checksum: $checksum,
                bytes: strlen($artifact->contents),
                nativeGoogleFormat: $native,
                metadata: is_array($artifact->metadata) ? $artifact->metadata : [],
            ), $attempt->id);

            $handoff = $this->formatHandoff->handOff(new DocumentFormatHandoffRequest(
                sourceReference: $request->sourceReference,
                sourceInstallationId: $request->sourceInstallationId,
                driveRunId: $run->id,
                acceptedObservationId: $accepted,
                artifactReference: $artifactReference,
                filename: $filename,
                mediaType: $artifact->mediaType,
                contents: $artifact->contents,
                checksum: $checksum,
                bytes: strlen($artifact->contents),
                metadata: [
                    'file_id' => $meta->fileId,
                    'revision_id' => $revisionId,
                    'source_mime_type' => $sourceMime,
                    'native_google_format' => $native,
                    'export_extension' => $exportExtension,
                ],
            ));

            $handoffPayload = [
                'status' => $handoff->status,
                'format_run_id' => $handoff->formatRunId,
                'details' => $handoff->details,
            ];

            $this->runs->succeedAttempt(
                $run,
                $attempt,
                [
                    'artifacts' => 1,
                    'accepted' => 1,
                    'bytes' => strlen($artifact->contents),
                    'file_id' => $meta->fileId,
                    'revision_id' => $revisionId,
                    'export_media_type' => $exportMediaType,
                    'format_handoff' => $handoffPayload,
                ],
                [$accepted],
            );
        } catch (Throwable $failure) {
            $retryable = ! $failure instanceof InvalidArgumentException
                && ! $failure instanceof MissingGoogleDriveCredentials
                && ! $failure instanceof GoogleDriveExportDenied;

            $this->runs->failAttempt(
                $run,
                $attempt,
                new RunFailure(
                    'google_drive_ingestion',
                    $failure->getMessage(),
                    $retryable,
                    ['failure' => $failure::class],
                ),
            );

            throw $failure;
        }

        $fresh = $this->runs->find($run->id) ?? $run;

        return new LaunchGoogleDriveIngestionResult(
            $fresh->id,
            false,
            $meta->fileId,
            $revisionId,
            $artifactReference,
            $exportMediaType,
            $exportExtension,
            $fresh->acceptedReferences,
            $handoffPayload,
        );
    }
}
