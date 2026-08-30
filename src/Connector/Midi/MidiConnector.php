<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Midi;

use InvalidArgumentException;
use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\Connector;
use Sifrious\Aleph\Connector\Contracts\DownloadsArtifacts;
use Sifrious\Aleph\Connector\Values\Artifact;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;

final class MidiConnector implements Connector, DownloadsArtifacts
{
    public function id(): string
    {
        return 'midi';
    }

    public function name(): string
    {
        return 'MIDI File';
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
            default => throw new InvalidArgumentException('MIDI ingestion requires an input mode of [path] or [file].'),
        };
    }

    private function fromPath(ArtifactRequest $request): Artifact
    {
        $path = is_string($request->parameters['path'] ?? null) ? $request->parameters['path'] : '';

        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('Local MIDI path input must point to a readable file.');
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new InvalidArgumentException('Local MIDI path input could not be read.');
        }

        $name = basename($path);
        $mediaType = $this->mediaType(
            is_string($request->parameters['media_type'] ?? null) ? $request->parameters['media_type'] : null,
            $name,
        );

        return $this->artifact($request, $contents, $mediaType, 'path', $name, $path);
    }

    private function fromFile(ArtifactRequest $request): Artifact
    {
        $encoded = is_string($request->parameters['contents_base64'] ?? null) ? $request->parameters['contents_base64'] : '';
        $name = is_string($request->parameters['name'] ?? null) ? $request->parameters['name'] : '';

        if ($name === '' || $encoded === '') {
            throw new InvalidArgumentException('Local MIDI file input requires both [name] and [contents_base64].');
        }

        $contents = base64_decode($encoded, true);

        if (! is_string($contents)) {
            throw new InvalidArgumentException('Local MIDI file input contained invalid base64 content.');
        }

        $mediaType = $this->mediaType(
            is_string($request->parameters['media_type'] ?? null) ? $request->parameters['media_type'] : null,
            $name,
        );

        return $this->artifact($request, $contents, $mediaType, 'file', $name, null);
    }

    private function artifact(
        ArtifactRequest $request,
        string $contents,
        string $mediaType,
        string $input,
        string $name,
        ?string $path,
    ): Artifact {
        $this->assertMidiMediaType($mediaType, $name);

        return new Artifact(
            reference: $request->artifactReference,
            mediaType: $mediaType,
            contents: $contents,
            metadata: array_filter([
                'source_reference' => $request->sourceReference,
                'input' => $input,
                'path' => $path,
                'name' => $name,
                'format_hint' => 'smf',
                'extension' => strtolower((string) pathinfo($name, PATHINFO_EXTENSION)),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        );
    }

    private function mediaType(?string $explicit, string $name): string
    {
        if ($explicit !== null && trim($explicit) !== '') {
            return strtolower($explicit);
        }

        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        return match ($extension) {
            'mid', 'midi' => 'audio/midi',
            default => 'application/octet-stream',
        };
    }

    private function assertMidiMediaType(string $mediaType, string $name): void
    {
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $allowedMedia = in_array($mediaType, ['audio/midi', 'audio/x-midi', 'audio/mid'], true);
        $allowedExtension = in_array($extension, ['mid', 'midi'], true);

        if (! $allowedMedia && ! $allowedExtension) {
            throw new InvalidArgumentException('MIDI input must resolve to a .mid/.midi file or audio/midi media type.');
        }
    }
}
