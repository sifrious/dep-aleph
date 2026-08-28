<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Scope;

use RuntimeException;

final class UnknownSourceInstallation extends RuntimeException
{
    public static function withId(string $id): self
    {
        return new self("Source installation [{$id}] does not exist.");
    }
}
