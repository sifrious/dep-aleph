<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GitHub;

use InvalidArgumentException;

final class GitHubActivitySources
{
    /** @var array<string, GitHubActivitySource> */
    private array $sources = [];

    public function register(GitHubActivitySource $source): void
    {
        $this->sources[$source->sourceReference()] = $source;
    }

    public function get(string $sourceReference): GitHubActivitySource
    {
        return $this->sources[$sourceReference]
            ?? throw new InvalidArgumentException("GitHub activity source [{$sourceReference}] is not registered.");
    }
}
