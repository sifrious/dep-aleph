<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Linear;

use InvalidArgumentException;

final readonly class LinearActivityPage
{
    /** @param list<LinearActivity> $activities */
    public function __construct(
        public array $activities,
        public ?string $endCursor,
        public bool $hasNextPage,
    ) {
        if ($hasNextPage && ($endCursor === null || $endCursor === '')) {
            throw new InvalidArgumentException('A continuing Linear page requires an end cursor.');
        }
    }
}
