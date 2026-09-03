<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\ScoreTab;

use InvalidArgumentException;
use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\Connector;
use Sifrious\Aleph\Connector\Contracts\DownloadsArtifacts;
use Sifrious\Aleph\Connector\Values\Artifact;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;

final readonly class ScoreTabConnector implements Connector, DownloadsArtifacts
{
    public function id(): string
    {
        return 'score-tab';
    }

    public function name(): string
    {
        return 'Score and Guitar Tab';
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
            default => throw new InvalidArgumentException('Score/tab ingestion requires an input mode of [path] or [file].'),
        };
    }

    private function fromPath(ArtifactRequest $request): Artifact
    {
        $path = is_string($request->parameters['path'] ?? null) ? $request->parameters['path'] : '';

        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('Local score/tab path input must point to a readable file.');
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new InvalidArgumentException('Local score/tab path input could not be read.');
        }

        $name = basename($path);
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
                'input' => 'path',
                'path' => $path,
                'name' => $name,
                'format' => $this->formatHint($name, $mediaType),
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    private function fromFile(ArtifactRequest $request): Artifact
    {
        $encoded = is_string($request->parameters['contents_base64'] ?? null) ? $request->parameters['contents_base64'] : '';
        $name = is_string($request->parameters['name'] ?? null) ? $request->parameters['name'] : '';

        if ($name === '' || $encoded === '') {
            throw new InvalidArgumentException('Local score/tab file input requires both [name] and [contents_base64].');
        }

        $contents = base64_decode($encoded, true);

        if (! is_string($contents)) {
            throw new InvalidArgumentException('Local score/tab file input contained invalid base64 content.');
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
                'format' => $this->formatHint($name, $mediaType),
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
            'musicxml', 'xml' => 'application/vnd.recordare.musicxml+xml',
            'mxl' => 'application/vnd.recordare.musicxml',
            'gp', 'gp3', 'gp4', 'gp5', 'gpx', 'gp7' => 'application/x-guitar-pro',
            'txt', 'tab' => 'text/plain',
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'application/octet-stream',
        };
    }

    private function formatHint(string $name, string $mediaType): string
    {
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        return match (true) {
            in_array($extension, ['musicxml', 'xml', 'mxl'], true)
                || str_contains($mediaType, 'musicxml') => 'musicxml',
            in_array($extension, ['gp', 'gp3', 'gp4', 'gp5', 'gpx', 'gp7'], true)
                || str_contains($mediaType, 'guitar-pro') => 'guitar_pro',
            in_array($extension, ['txt', 'tab'], true)
                || str_starts_with($mediaType, 'text/') => 'ascii_tab',
            $extension === 'pdf' || $mediaType === 'application/pdf' => 'score_pdf',
            str_starts_with($mediaType, 'image/') => 'score_image',
            default => 'score_tab',
        };
    }
}
