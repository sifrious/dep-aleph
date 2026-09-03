<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Podcast;

use Sifrious\Aleph\Ingestion\LaunchAuthorization;

final readonly class LaunchPodcastIngestionRequest
{
    public function __construct(
        public string $sourceInstallationId,
        public string $sourceReference,
        public string $reference,
        public LaunchAuthorization $authorization,
    ) {}
}
