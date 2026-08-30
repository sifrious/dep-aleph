<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\VideoFile;

use InvalidArgumentException;
use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\Connector;
use Sifrious\Aleph\Connector\Contracts\DownloadsArtifacts;
use Sifrious\Aleph\Connector\Values\Artifact;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;

final readonly class VideoFileConnector implements Connector, DownloadsArtifacts
{
    public function id(): string
    {
        return 'video-file';
    }

    public function name(): string
    {
        return 'Local Video File';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function configuration(): ConfigurationSchema
    {
        return new ConfigurationSchema;
    }

    public function downloadArtifact(ArtifactRequest $request): Artifact
    {
        $input = is_string($request->parameters['input'] ?? null) ? $request->parameters['input'] : null;

        return match ($input) {
            'path' => $this->fromPath($request),
            'file' => $this->fromFile($request),
            default => throw new InvalidArgumentException('Video file ingestion requires an input mode of [path] or [file].'),
        };
    }

    private function fromPath(ArtifactRequest $request): Artifact
    {
        $path = is_string($request->parameters['path'] ?? null) ? $request->parameters['path'] : '';

        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('Local video path input must point to a readable file.');
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new InvalidArgumentException('Local video path input could not be read.');
        }

        $mediaType = $this->mediaType(
            is_string($request->parameters['media_type'] ?? null) ? $request->parameters['media_type'] : null,
            basename($path),
        );

        return new Artifact(
            reference: $request->artifactReference,
            mediaType: $mediaType,
            contents: $contents,
            metadata: array_filter([
                'source_reference' => $request->sourceReference,
                'input' => 'path',
                'path' => $path,
                'name' => basename($path),
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    private function fromFile(ArtifactRequest $request): Artifact
    {
        $encoded = is_string($request->parameters['contents_base64'] ?? null) ? $request->parameters['contents_base64'] : '';
        $name = is_string($request->parameters['name'] ?? null) ? $request->parameters['name'] : '';

        if ($name === '' || $encoded === '') {
            throw new InvalidArgumentException('Local video file input requires both [name] and [contents_base64].');
        }

        $contents = base64_decode($encoded, true);

        if (! is_string($contents)) {
            throw new InvalidArgumentException('Local video file input contained invalid base64 content.');
        }

        $mediaType = $this->mediaType(
            is_string($request->parameters['media_type'] ?? null) ? $request->parameters['media_type'] : null,
            $name,
        );

        return new Artifact(
            reference: $request->artifactReference,
            mediaType: $mediaType,
            contents: $contents,
            metadata: array_filter([
                'source_reference' => $request->sourceReference,
                'input' => 'file',
                'name' => $name,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    private function mediaType(?string $explicit, string $name): string
    {
        if ($explicit !== null && trim($explicit) !== '') {
            return $explicit;
        }

        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        return match ($extension) {
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'm4v' => 'video/x-m4v',
            'avi' => 'video/x-msvideo',
            'mkv' => 'video/x-matroska',
            'webm' => 'video/webm',
            default => 'application/octet-stream',
        };
    }
}
