<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GitHub;

use InvalidArgumentException;

final readonly class GitHubTokenSecret
{
    public function __construct(private string $value)
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException('A resolved GitHub token cannot be empty.');
        }
    }

    public function reveal(): string
    {
        return $this->value;
    }
}
