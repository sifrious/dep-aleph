<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GoogleDrive;

use Sifrious\Aleph\Ingestion\LaunchAuthorization;

final readonly class LaunchGoogleDriveIngestionRequest
{
    public function __construct(
        public string $sourceInstallationId,
        public string $sourceReference,
        public string $fileId,
        public LaunchAuthorization $authorization,
        public ?string $preferredExtension = null,
    ) {}
}
