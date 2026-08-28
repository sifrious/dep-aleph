<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Git;

use InvalidArgumentException;

final readonly class GitBlameRange
{
    public function __construct(
        public string $path,
        public int $startLine,
        public int $endLine,
        public string $commitSha,
        public string $authorName,
        public string $authorEmail,
    ) {
        if (trim($path) === '' || $startLine < 1 || $endLine < $startLine || preg_match('/^[a-f0-9]{40}$/', $commitSha) !== 1) {
            throw new InvalidArgumentException('A Git blame range requires a valid path, line range, and commit SHA.');
        }
    }
}
