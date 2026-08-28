<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Inventory;

final readonly class JsonInventory
{
    public function encode(Inventory $inventory): string
    {
        return json_encode(
            $inventory->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";
    }
}
