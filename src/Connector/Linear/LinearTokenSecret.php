<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Linear;

use InvalidArgumentException;

final readonly class LinearTokenSecret
{
    public function __construct(private string $value, public bool $oauth = true)
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException('A resolved Linear token cannot be empty.');
        }
    }

    public function authorization(): string
    {
        return $this->oauth ? 'Bearer '.$this->value : $this->value;
    }
}
