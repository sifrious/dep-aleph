<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\AppleMail;

use Sifrious\Aleph\Ingestion\LaunchAuthorization;

final readonly class LaunchAppleMailIngestionRequest
{
    public function __construct(
        public string $sourceInstallationId,
        public string $sourceReference,
        public LocalAppleMailMessage $message,
        public LaunchAuthorization $authorization,
    ) {}
}
