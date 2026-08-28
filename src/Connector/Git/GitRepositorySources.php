<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Git;

use InvalidArgumentException;

final class GitRepositorySources
{
    /** @var array<string, GitRepositorySource> */
    private array $sources = [];

    public function register(GitRepositorySource $source): void
    {
        $this->sources[$source->repository()->reference] = $source;
    }

    public function get(string $reference): GitRepositorySource
    {
        return $this->sources[$reference] ?? throw new InvalidArgumentException("Git repository source [{$reference}] is not registered.");
    }
}
