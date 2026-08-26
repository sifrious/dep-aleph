<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

use InvalidArgumentException;

final readonly class CrawlLimits
{
    public function __construct(
        public int $maxPages,
        public int $maxDepth,
    ) {
        if ($maxPages < 1) {
            throw new InvalidArgumentException('A crawl must allow at least one page.');
        }

        if ($maxDepth < 0) {
            throw new InvalidArgumentException('A crawl depth limit cannot be negative.');
        }
    }

    public function with(?int $maxPages, ?int $maxDepth): self
    {
        return new self($maxPages ?? $this->maxPages, $maxDepth ?? $this->maxDepth);
    }

    /**
     * @return array{max_pages: int, max_depth: int}
     */
    public function toArray(): array
    {
        return ['max_pages' => $this->maxPages, 'max_depth' => $this->maxDepth];
    }
}
