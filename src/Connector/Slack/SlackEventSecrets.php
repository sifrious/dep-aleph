<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

use InvalidArgumentException;

final class SlackEventSecrets
{
    /** @var array<string, string> */
    private array $secrets = [];

    public function register(string $installationId, string $secret): void
    {
        if ($secret === '') {
            throw new InvalidArgumentException('Slack event secret cannot be empty.');
        }

        $this->secrets[$installationId] = $secret;
    }

    public function get(string $installationId): string
    {
        return $this->secrets[$installationId] ?? throw new InvalidArgumentException('Slack event secret is not registered.');
    }
}
