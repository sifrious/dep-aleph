<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Email;

use InvalidArgumentException;

final readonly class GmailTokenSecret
{
    public function __construct(private string $value)
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException('A resolved Gmail token cannot be empty.');
        }
    }

    public function reveal(): string
    {
        return $this->value;
    }
}
