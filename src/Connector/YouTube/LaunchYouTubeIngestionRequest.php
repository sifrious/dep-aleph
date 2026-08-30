<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\YouTube;

use Sifrious\Aleph\Ingestion\LaunchAuthorization;

final readonly class LaunchYouTubeIngestionRequest
{
    public function __construct(
        public string $sourceInstallationId,
        public string $sourceReference,
        public string $url,
        public LaunchAuthorization $authorization,
    ) {}
}
