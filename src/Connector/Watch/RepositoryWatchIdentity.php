<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Watch;

use Symfony\Component\Uid\Ulid;

final class RepositoryWatchIdentity
{
    public static function for(string $reference): string
    {
        $hex = substr(hash('sha256', $reference), 0, 32);
        $uuid = implode('-', [
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ]);

        return Ulid::fromRfc4122($uuid)->toBase32();
    }
}
