<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Image;

use Sifrious\Aleph\Ingestion\LaunchAuthorization;

final readonly class LaunchImageIngestionRequest
{
    private function __construct(
        public string $sourceInstallationId,
        public string $sourceReference,
        public LaunchAuthorization $authorization,
        public ?string $path,
        public ?LocalImageFilePayload $file,
        public ?ImageCapturePayload $capture,
    ) {}

    public static function fromPath(
        string $sourceInstallationId,
        string $sourceReference,
        string $path,
        LaunchAuthorization $authorization,
    ): self {
        return new self($sourceInstallationId, $sourceReference, $authorization, $path, null, null);
    }

    public static function fromFile(
        string $sourceInstallationId,
        string $sourceReference,
        LocalImageFilePayload $file,
        LaunchAuthorization $authorization,
    ): self {
        return new self($sourceInstallationId, $sourceReference, $authorization, null, $file, null);
    }

    public static function fromCapture(
        string $sourceInstallationId,
        string $sourceReference,
        ImageCapturePayload $capture,
        LaunchAuthorization $authorization,
    ): self {
        return new self($sourceInstallationId, $sourceReference, $authorization, null, null, $capture);
    }
}
