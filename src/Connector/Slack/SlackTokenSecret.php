<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

use InvalidArgumentException;

final readonly class SlackTokenSecret
{
    public function __construct(private string $value)
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException('A resolved Slack token cannot be empty.');
        }
    }

    public function reveal(): string
    {
        return $this->value;
    }
}
