<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\NativePhpDesktop;

use InvalidArgumentException;
use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\Connector;
use Sifrious\Aleph\Connector\Contracts\DownloadsArtifacts;
use Sifrious\Aleph\Connector\Values\Artifact;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;

final readonly class NativePhpDesktopConnector implements Connector, DownloadsArtifacts
{
    public function id(): string
    {
        return 'nativephp-desktop';
    }

    public function name(): string
    {
        return 'NativePHP Desktop';
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
        $body = $request->parameters['body'] ?? null;

        if (! is_string($body) || trim($body) === '') {
            throw new InvalidArgumentException('NativePHP Desktop downloadArtifact requires a non-empty body parameter.');
        }

        $sha256 = $request->parameters['sha256'] ?? hash('sha256', $body);
        $hash = hash('sha256', $body);

        if (! is_string($sha256) || $sha256 !== $hash) {
            throw new InvalidArgumentException('NativePHP Desktop body hash does not match the provided sha256 parameter.');
        }

        return new Artifact(
            reference: $request->artifactReference,
            mediaType: 'text/plain; charset=utf-8',
            contents: $body,
            metadata: [
                'source_identity' => 'nativephp-desktop',
                'sha256' => $hash,
                'bytes' => strlen($body),
                'source_reference' => $request->sourceReference,
            ],
        );
    }
}
