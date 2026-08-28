<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Linear;

use InvalidArgumentException;

final class LinearWebhookSecrets
{
    /** @var array<string, string> */
    private array $secrets = [];

    public function register(string $sourceInstallationId, string $secret): void
    {
        if (trim($sourceInstallationId) === '' || $secret === '') {
            throw new InvalidArgumentException('A Linear webhook secret requires an installation and secret material.');
        }

        $this->secrets[$sourceInstallationId] = $secret;
    }

    public function get(string $sourceInstallationId): string
    {
        return $this->secrets[$sourceInstallationId]
            ?? throw new InvalidArgumentException("No Linear webhook secret is registered for [{$sourceInstallationId}].");
    }
}
