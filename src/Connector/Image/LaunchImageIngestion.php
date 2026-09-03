<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Image;

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

final readonly class LaunchImageIngestion
{
    public function __construct(
        private LaunchIngestion $launcher,
        private IngestionRuns $runs,
        private ConnectorRegistry $connectors,
        private ImageObservationWriter $writer,
        private ImageMetadataInspector $inspector = new BinaryImageMetadataInspector,
    ) {}

    public function launch(LaunchImageIngestionRequest $request): LaunchImageIngestionResult
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
            return new LaunchImageIngestionResult($run->id, true, $run->acceptedReferences);
        }

        $existing = $this->runs->find($run->id);

        if ($existing !== null && $existing->acceptedReferences !== []) {
            return new LaunchImageIngestionResult($existing->id, false, $existing->acceptedReferences);
        }

        if ($existing !== null && $this->runs->activeAttempt($existing) !== null) {
            return new LaunchImageIngestionResult($existing->id, false, $existing->acceptedReferences);
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
                throw new InvalidArgumentException('Image content hash changed between launch and artifact download.');
            }

            $imageMeta = $this->imageMetadata($artifact->metadata, $artifact->contents, $artifact->mediaType);
            $accepted = $this->writer->write(new ImageArtifactSubmission(
                sourceReference: $request->sourceReference,
                sourceInstallationId: $request->sourceInstallationId,
                runId: $run->id,
                artifactReference: $prepared['artifact_reference'],
                mediaType: $artifact->mediaType,
                contents: $artifact->contents,
                checksum: $checksum,
                bytes: strlen($artifact->contents),
                image: $imageMeta,
                metadata: $artifact->metadata,
            ), $attempt->id);

            $stats = [
                'artifacts' => 1,
                'accepted' => 1,
                'bytes' => strlen($artifact->contents),
            ];

            if ($imageMeta->width !== null) {
                $stats['width'] = $imageMeta->width;
            }

            if ($imageMeta->height !== null) {
                $stats['height'] = $imageMeta->height;
            }

            $this->runs->succeedAttempt(
                $run,
                $attempt,
                $stats,
                [$accepted],
            );
        } catch (Throwable $failure) {
            $this->runs->failAttempt(
                $run,
                $attempt,
                new RunFailure('image_ingestion', $failure->getMessage(), true, ['failure' => $failure::class]),
            );

            throw $failure;
        }

        $fresh = $this->runs->find($run->id) ?? $run;

        return new LaunchImageIngestionResult($fresh->id, false, $fresh->acceptedReferences);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function imageMetadata(array $metadata, string $contents, string $mediaType): ImageMetadata
    {
        $image = $metadata['image'] ?? null;

        if (is_array($image) && is_string($image['exif']['presence'] ?? null)) {
            $presence = ImageExifPresence::tryFrom($image['exif']['presence']);

            if ($presence !== null) {
                return new ImageMetadata(
                    width: isset($image['width']) && is_numeric($image['width']) ? (int) $image['width'] : null,
                    height: isset($image['height']) && is_numeric($image['height']) ? (int) $image['height'] : null,
                    colorSpace: is_string($image['color_space'] ?? null) ? $image['color_space'] : null,
                    capturedAt: is_string($image['captured_at'] ?? null) ? $image['captured_at'] : null,
                    modifiedAt: is_string($image['modified_at'] ?? null) ? $image['modified_at'] : null,
                    exifPresence: $presence,
                    exifFields: is_array($image['exif']['fields'] ?? null) ? $image['exif']['fields'] : [],
                );
            }
        }

        $hints = [];

        if (is_string($metadata['captured_at'] ?? null)) {
            $hints['captured_at'] = $metadata['captured_at'];
        }

        if (is_string($metadata['modified_at'] ?? null)) {
            $hints['modified_at'] = $metadata['modified_at'];
        }

        return $this->inspector->inspect($contents, $mediaType, $hints);
    }

    /**
     * @return array{
     *     artifact_reference: string,
     *     checksum: string,
     *     run_parameters: array<string, mixed>,
     *     connector_parameters: array<string, mixed>
     * }
     */
    private function prepare(LaunchImageIngestionRequest $request): array
    {
        $provided = array_filter([
            $request->path !== null,
            $request->file !== null,
            $request->capture !== null,
        ]);

        if (count($provided) !== 1) {
            throw new InvalidArgumentException('Image ingestion accepts exactly one of path, file, or capture input.');
        }

        if ($request->path !== null) {
            $raw = trim($request->path);
            $path = $raw === '' ? false : realpath($raw);

            if ($path === false || ! is_file($path) || ! is_readable($path)) {
                throw new InvalidArgumentException('Image path input must point to a readable file.');
            }

            $checksum = hash_file('sha256', $path);

            if (! is_string($checksum)) {
                throw new InvalidArgumentException('Image ingestion could not hash the supplied path input.');
            }

            return [
                'artifact_reference' => 'file://'.$path,
                'checksum' => $checksum,
                'run_parameters' => [
                    'input' => 'path',
                    'path' => $path,
                    'name' => basename($path),
                    'sha256' => $checksum,
                ],
                'connector_parameters' => [
                    'input' => 'path',
                    'path' => $path,
                ],
            ];
        }

        if ($request->file !== null) {
            $file = $request->file;

            if (trim($file->name) === '') {
                throw new InvalidArgumentException('Image file payload requires a stable file name.');
            }

            $checksum = hash('sha256', $file->contents);
            $artifactReference = 'memory://'.$file->name.'#sha256:'.$checksum;

            return [
                'artifact_reference' => $artifactReference,
                'checksum' => $checksum,
                'run_parameters' => array_filter([
                    'input' => 'file',
                    'name' => $file->name,
                    'sha256' => $checksum,
                    'media_type' => $file->mediaType,
                ], static fn (mixed $value): bool => $value !== null),
                'connector_parameters' => array_filter([
                    'input' => 'file',
                    'name' => $file->name,
                    'contents_base64' => base64_encode($file->contents),
                    'media_type' => $file->mediaType,
                ], static fn (mixed $value): bool => $value !== null),
            ];
        }

        $capture = $request->capture;

        if ($capture === null) {
            throw new InvalidArgumentException('Image ingestion requires a path, file, or capture input.');
        }

        $checksum = hash('sha256', $capture->contents);
        $capturedAt = $capture->capturedAt->format(DATE_ATOM);
        $artifactReference = 'capture://'.$capture->name.'#sha256:'.$checksum;

        return [
            'artifact_reference' => $artifactReference,
            'checksum' => $checksum,
            'run_parameters' => array_filter([
                'input' => 'capture',
                'name' => $capture->name,
                'sha256' => $checksum,
                'media_type' => $capture->mediaType,
                'captured_at' => $capturedAt,
            ], static fn (mixed $value): bool => $value !== null),
            'connector_parameters' => array_filter([
                'input' => 'capture',
                'name' => $capture->name,
                'contents_base64' => base64_encode($capture->contents),
                'media_type' => $capture->mediaType,
                'captured_at' => $capturedAt,
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }
}
