<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\SpokenSound;

use InvalidArgumentException;
use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\Connector;
use Sifrious\Aleph\Connector\Contracts\DownloadsArtifacts;
use Sifrious\Aleph\Connector\Values\Artifact;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;

final class SpokenSoundConnector implements Connector, DownloadsArtifacts
{
    public function id(): string
    {
        return 'spoken-sound';
    }

    public function name(): string
    {
        return 'Spoken Sound';
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
            default => throw new InvalidArgumentException('Spoken sound ingestion requires an input mode of [path] or [file].'),
        };
    }

    private function fromPath(ArtifactRequest $request): Artifact
    {
        $path = is_string($request->parameters['path'] ?? null) ? $request->parameters['path'] : '';

        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('Local spoken sound path input must point to a readable file.');
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new InvalidArgumentException('Local spoken sound path input could not be read.');
        }

        $mediaType = $this->mediaType(
            is_string($request->parameters['media_type'] ?? null) ? $request->parameters['media_type'] : null,
            basename($path),
        );

        return $this->artifact(
            $request,
            $contents,
            $mediaType,
            'path',
            basename($path),
            $path,
        );
    }

    private function fromFile(ArtifactRequest $request): Artifact
    {
        $encoded = is_string($request->parameters['contents_base64'] ?? null) ? $request->parameters['contents_base64'] : '';
        $name = is_string($request->parameters['name'] ?? null) ? $request->parameters['name'] : '';

        if ($name === '' || $encoded === '') {
            throw new InvalidArgumentException('Local spoken sound file input requires both [name] and [contents_base64].');
        }

        $contents = base64_decode($encoded, true);

        if (! is_string($contents)) {
            throw new InvalidArgumentException('Local spoken sound file input contained invalid base64 content.');
        }

        $mediaType = $this->mediaType(
            is_string($request->parameters['media_type'] ?? null) ? $request->parameters['media_type'] : null,
            $name,
        );

        return $this->artifact(
            $request,
            $contents,
            $mediaType,
            'file',
            $name,
            null,
        );
    }

    private function artifact(
        ArtifactRequest $request,
        string $contents,
        string $mediaType,
        string $input,
        string $name,
        ?string $path,
    ): Artifact {
        if (! str_starts_with($mediaType, 'audio/')) {
            throw new InvalidArgumentException('Spoken sound input must resolve to an audio media type.');
        }

        $duration = $request->parameters['duration_seconds'] ?? null;

        return new Artifact(
            reference: $request->artifactReference,
            mediaType: $mediaType,
            contents: $contents,
            metadata: array_filter([
                'source_reference' => $request->sourceReference,
                'input' => $input,
                'path' => $path,
                'name' => $name,
                'duration_seconds' => is_numeric($duration) ? (float) $duration : null,
                'container' => $this->containerMetadata($name, $mediaType, $request->parameters),
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    private function mediaType(?string $explicit, string $name): string
    {
        if ($explicit !== null && trim($explicit) !== '') {
            return strtolower($explicit);
        }

        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        return match ($extension) {
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'wav' => 'audio/wav',
            'ogg', 'oga' => 'audio/ogg',
            'webm' => 'audio/webm',
            'flac' => 'audio/flac',
            default => 'application/octet-stream',
        };
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function containerMetadata(string $name, string $mediaType, array $parameters): array
    {
        $provided = is_array($parameters['container'] ?? null) ? $parameters['container'] : [];

        return array_filter(array_merge($provided, [
            'extension' => strtolower((string) pathinfo($name, PATHINFO_EXTENSION)),
            'filename' => $name,
            'mime_type' => $mediaType,
        ]), static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
