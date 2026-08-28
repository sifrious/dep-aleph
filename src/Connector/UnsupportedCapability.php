<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector;

use RuntimeException;

final class UnsupportedCapability extends RuntimeException
{
    private function __construct(public readonly Rejection $rejection)
    {
        parent::__construct($rejection->message);
    }

    public static function from(Rejection $rejection): self
    {
        return new self($rejection);
    }
}
