<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GitHub;

final readonly class GitHubActivityPage
{
    /**
     * @param  list<GitHubActivity>  $activities
     */
    public function __construct(
        public array $activities,
        public ?string $endCursor,
        public bool $hasNextPage,
    ) {}
}
