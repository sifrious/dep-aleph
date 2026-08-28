<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Git;

use DateTimeImmutable;

final readonly class GitSnapshot
{
    /**
     * @param  list<GitCommit>  $commits
     * @param  list<GitTreeEntry>  $previousTree
     * @param  list<GitTreeEntry>  $tree
     * @param  list<GitBlameRange>  $blame
     */
    public function __construct(
        public string $ref,
        public string $headSha,
        public DateTimeImmutable $capturedAt,
        public ?string $previousHeadSha,
        public bool $previousIsAncestor,
        public array $commits,
        public array $previousTree,
        public array $tree,
        public string $diff,
        public array $blame,
    ) {}

    public function forcePushed(): bool
    {
        return $this->previousHeadSha !== null && ! $this->previousIsAncestor;
    }
}
