<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Bibliography;

use RuntimeException;

final class ImmutableBibliographicConflict extends RuntimeException
{
    public static function forField(string $entity, string $identity, string $field): self
    {
        return new self("{$entity} [{$identity}] conflicts on immutable field [{$field}].");
    }
}
