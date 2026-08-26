<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

use RuntimeException;

final class UnknownWebSource extends RuntimeException
{
    /**
     * @param  list<string>  $known
     */
    public static function named(string $key, array $known): self
    {
        $available = $known === [] ? 'none are configured' : 'configured sources are '.implode(', ', $known);

        return new self("Unknown web source [{$key}]; {$available}.");
    }
}
