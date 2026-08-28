<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GitHub;

use InvalidArgumentException;

final class GitHubWebhookSecrets
{
    /** @var array<string, string> */
    private array $secrets = [];

    public function register(string $sourceReference, string $secret): void
    {
        if (trim($sourceReference) === '' || $secret === '') {
            throw new InvalidArgumentException('A GitHub webhook secret requires a source reference and secret material.');
        }

        $this->secrets[$sourceReference] = $secret;
    }

    public function get(string $sourceReference): string
    {
        return $this->secrets[$sourceReference]
            ?? throw new InvalidArgumentException("No GitHub webhook secret is registered for [{$sourceReference}].");
    }
}
