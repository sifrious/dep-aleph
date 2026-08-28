<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use Sifrious\Aleph\Connector\Capability;

final readonly class LaunchIngestionRequest
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public string $sourceInstallationId,
        public string $sourceReference,
        public Capability $capability,
        public array $parameters,
        public string $idempotencyKey,
        public LaunchAuthorization $authorization,
    ) {}
}
