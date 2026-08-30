<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\VideoFile;

use Sifrious\Aleph\Ingestion\LaunchAuthorization;

final readonly class LaunchLocalVideoIngestionRequest
{
    private function __construct(
        public string $sourceInstallationId,
        public string $sourceReference,
        public LaunchAuthorization $authorization,
        public ?string $path,
        public ?LocalVideoFilePayload $file,
    ) {}

    public static function fromPath(
        string $sourceInstallationId,
        string $sourceReference,
        string $path,
        LaunchAuthorization $authorization,
    ): self {
        return new self($sourceInstallationId, $sourceReference, $authorization, $path, null);
    }

    public static function fromFile(
        string $sourceInstallationId,
        string $sourceReference,
        LocalVideoFilePayload $file,
        LaunchAuthorization $authorization,
    ): self {
        return new self($sourceInstallationId, $sourceReference, $authorization, null, $file);
    }
}
