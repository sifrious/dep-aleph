<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Git;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class GitCommit
{
    /**
     * @param  list<string>  $parents
     */
    public function __construct(
        public string $sha,
        public array $parents,
        public string $authorName,
        public string $authorEmail,
        public DateTimeImmutable $authoredAt,
        public DateTimeImmutable $committedAt,
        public string $message,
    ) {
        if (preg_match('/^[a-f0-9]{40}$/', $sha) !== 1) {
            throw new InvalidArgumentException('A Git commit requires a full lowercase SHA-1.');
        }
    }
}
