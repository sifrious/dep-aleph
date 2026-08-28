<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use InvalidArgumentException;

final readonly class FreshnessExpectation
{
    public function __construct(
        public int $intervalSeconds,
        public int $staleAfterSeconds,
    ) {
        if ($intervalSeconds < 1 || $staleAfterSeconds < $intervalSeconds) {
            throw new InvalidArgumentException('Freshness requires a positive interval and a stale boundary at or after that interval.');
        }
    }
}
