<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Handwriting;

use Sifrious\Aleph\Ingestion\LaunchAuthorization;

final readonly class LaunchHandwritingIngestionRequest
{
    private function __construct(
        public string $sourceInstallationId,
        public string $sourceReference,
        public LaunchAuthorization $authorization,
        public ?string $path,
        public ?LocalHandwritingFilePayload $file,
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
        LocalHandwritingFilePayload $file,
        LaunchAuthorization $authorization,
    ): self {
        return new self($sourceInstallationId, $sourceReference, $authorization, null, $file);
    }
}
