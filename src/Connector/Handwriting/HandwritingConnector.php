<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Handwriting;

use InvalidArgumentException;
use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\Connector;
use Sifrious\Aleph\Connector\Contracts\DownloadsArtifacts;
use Sifrious\Aleph\Connector\Values\Artifact;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;

final readonly class HandwritingConnector implements Connector, DownloadsArtifacts
{
    public function id(): string
    {
        return 'handwriting';
    }

    public function name(): string
    {
        return 'Handwriting Image';
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
            default => throw new InvalidArgumentException('Handwriting ingestion requires an input mode of [path] or [file].'),
        };
    }

    private function fromPath(ArtifactRequest $request): Artifact
    {
        $path = is_string($request->parameters['path'] ?? null) ? $request->parameters['path'] : '';

        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('Local handwriting path input must point to a readable file.');
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new InvalidArgumentException('Local handwriting path input could not be read.');
        }

        $name = basename($path);
        $mediaType = $this->mediaType(
            is_string($request->parameters['media_type'] ?? null) ? $request->parameters['media_type'] : null,
            $name,
        );
        $this->assertImageMediaType($mediaType);

        return new Artifact(
            reference: $request->artifactReference,
            mediaType: $mediaType,
            contents: $contents,
            metadata: array_filter([
                'source_reference' => $request->sourceReference,
                'input' => 'path',
                'path' => $path,
                'name' => $name,
                'format' => 'handwritten_image',
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    private function fromFile(ArtifactRequest $request): Artifact
    {
        $encoded = is_string($request->parameters['contents_base64'] ?? null) ? $request->parameters['contents_base64'] : '';
        $name = is_string($request->parameters['name'] ?? null) ? $request->parameters['name'] : '';

        if ($name === '' || $encoded === '') {
            throw new InvalidArgumentException('Local handwriting file input requires both [name] and [contents_base64].');
        }

        $contents = base64_decode($encoded, true);

        if (! is_string($contents)) {
            throw new InvalidArgumentException('Local handwriting file input contained invalid base64 content.');
        }

        $mediaType = $this->mediaType(
            is_string($request->parameters['media_type'] ?? null) ? $request->parameters['media_type'] : null,
            $name,
        );
        $this->assertImageMediaType($mediaType);

        return new Artifact(
            reference: $request->artifactReference,
            mediaType: $mediaType,
            contents: $contents,
            metadata: array_filter([
                'source_reference' => $request->sourceReference,
                'input' => 'file',
                'name' => $name,
                'format' => 'handwritten_image',
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
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'tif', 'tiff' => 'image/tiff',
            'bmp' => 'image/bmp',
            default => 'application/octet-stream',
        };
    }

    private function assertImageMediaType(string $mediaType): void
    {
        if (! str_starts_with($mediaType, 'image/')) {
            throw new InvalidArgumentException('Handwriting ingestion accepts image media types only.');
        }
    }
}
