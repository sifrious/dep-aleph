<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector;

use RuntimeException;

final class UnknownConnector extends RuntimeException
{
    public static function named(string $id): self
    {
        return new self("No connector is registered with id [{$id}].");
    }
}
