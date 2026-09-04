<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Bibliography;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Stringable;

abstract readonly class BibliographicId implements Stringable
{
    final public function __construct(public string $value)
    {
        if (! Str::isUlid($value)) {
            throw new InvalidArgumentException("Bibliographic ID [{$value}] must be a ULID.");
        }
    }

    final public function __toString(): string
    {
        return $this->value;
    }
}
