<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\NativePhpDesktop;

use Sifrious\Aleph\Ingestion\LaunchAuthorization;

final readonly class LaunchNativePhpDesktopFreeformIngestionRequest
{
    public function __construct(
        public string $sourceInstallationId,
        public string $sourceReference,
        public string $body,
        public LaunchAuthorization $authorization,
    ) {}
}
