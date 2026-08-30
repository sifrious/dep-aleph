<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\NativePhpDesktop;

use LogicException;
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
        throw new LogicException('NativePHP Desktop freeform capture submits payload directly and does not download artifacts.');
    }
}
