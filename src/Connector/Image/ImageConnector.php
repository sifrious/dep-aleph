<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Image;

use InvalidArgumentException;
use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\Connector;
use Sifrious\Aleph\Connector\Contracts\DownloadsArtifacts;
use Sifrious\Aleph\Connector\Values\Artifact;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;

final readonly class ImageConnector implements Connector, DownloadsArtifacts
{
    public function __construct(
        private ImageMetadataInspector $inspector = new BinaryImageMetadataInspector,
    ) {}

    public function id(): string
    {
        return 'image';
    }

    public function name(): string
    {
        return 'Image';
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
            'capture' => $this->fromCapture($request),
            default => throw new InvalidArgumentException('Image ingestion requires an input mode of [path], [file], or [capture].'),
        };
    }

    private function fromPath(ArtifactRequest $request): Artifact
    {
        $path = is_string($request->parameters['path'] ?? null) ? $request->parameters['path'] : '';

        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('Local image path input must point to a readable file.');
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new InvalidArgumentException('Local image path input could not be read.');
        }

        $name = basename($path);
        $mediaType = $this->mediaType(
            is_string($request->parameters['media_type'] ?? null) ? $request->parameters['media_type'] : null,
            $name,
        );
        $this->assertImageMediaType($mediaType);
        $modifiedAt = gmdate('c', (int) filemtime($path));
        $image = $this->inspector->inspect($contents, $mediaType, [
            'input' => 'path',
            'path' => $path,
            'modified_at' => $modifiedAt,
        ]);

        return new Artifact(
            reference: $request->artifactReference,
            mediaType: $mediaType,
            contents: $contents,
            metadata: [
                'source_reference' => $request->sourceReference,
                'input' => 'path',
                'path' => $path,
                'name' => $name,
                'image' => $image->toArray(),
            ],
        );
    }

    private function fromFile(ArtifactRequest $request): Artifact
    {
        $encoded = is_string($request->parameters['contents_base64'] ?? null) ? $request->parameters['contents_base64'] : '';
        $name = is_string($request->parameters['name'] ?? null) ? $request->parameters['name'] : '';

        if ($name === '' || $encoded === '') {
            throw new InvalidArgumentException('Local image file input requires both [name] and [contents_base64].');
        }

        $contents = base64_decode($encoded, true);

        if (! is_string($contents)) {
            throw new InvalidArgumentException('Local image file input contained invalid base64 content.');
        }

        $mediaType = $this->mediaType(
            is_string($request->parameters['media_type'] ?? null) ? $request->parameters['media_type'] : null,
            $name,
        );
        $this->assertImageMediaType($mediaType);
        $image = $this->inspector->inspect($contents, $mediaType, [
            'input' => 'file',
        ]);

        return new Artifact(
            reference: $request->artifactReference,
            mediaType: $mediaType,
            contents: $contents,
            metadata: [
                'source_reference' => $request->sourceReference,
                'input' => 'file',
                'name' => $name,
                'image' => $image->toArray(),
            ],
        );
    }

    private function fromCapture(ArtifactRequest $request): Artifact
    {
        $encoded = is_string($request->parameters['contents_base64'] ?? null) ? $request->parameters['contents_base64'] : '';
        $name = is_string($request->parameters['name'] ?? null) ? $request->parameters['name'] : '';
        $capturedAt = is_string($request->parameters['captured_at'] ?? null) ? $request->parameters['captured_at'] : '';

        if ($name === '' || $encoded === '' || $capturedAt === '') {
            throw new InvalidArgumentException('Image capture input requires [name], [contents_base64], and [captured_at].');
        }

        $contents = base64_decode($encoded, true);

        if (! is_string($contents)) {
            throw new InvalidArgumentException('Image capture input contained invalid base64 content.');
        }

        $mediaType = $this->mediaType(
            is_string($request->parameters['media_type'] ?? null) ? $request->parameters['media_type'] : null,
            $name,
        );
        $this->assertImageMediaType($mediaType);
        $image = $this->inspector->inspect($contents, $mediaType, [
            'input' => 'capture',
            'captured_at' => $capturedAt,
        ]);

        return new Artifact(
            reference: $request->artifactReference,
            mediaType: $mediaType,
            contents: $contents,
            metadata: [
                'source_reference' => $request->sourceReference,
                'input' => 'capture',
                'name' => $name,
                'captured_at' => $capturedAt,
                'image' => $image->toArray(),
            ],
        );
    }

    private function mediaType(?string $explicit, string $name): string
    {
        if ($explicit !== null && trim($explicit) !== '') {
            return strtolower($explicit);
        }

        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        return match ($extension) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'heic', 'heif' => 'image/heic',
            'tif', 'tiff' => 'image/tiff',
            'bmp' => 'image/bmp',
            default => 'application/octet-stream',
        };
    }

    private function assertImageMediaType(string $mediaType): void
    {
        if (! str_starts_with($mediaType, 'image/')) {
            throw new InvalidArgumentException('Image ingestion accepts image media types only.');
        }
    }
}
