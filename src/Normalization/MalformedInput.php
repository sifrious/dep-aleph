<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Normalization;

use RuntimeException;

final class MalformedInput extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
