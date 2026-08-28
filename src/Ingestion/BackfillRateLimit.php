<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use InvalidArgumentException;

final readonly class BackfillRateLimit
{
    public function __construct(
        public int $requests,
        public int $perSeconds,
    ) {
        if ($requests < 1 || $perSeconds < 1) {
            throw new InvalidArgumentException('Backfill rate limits must be positive.');
        }
    }

    /**
     * @return array{requests: int, per_seconds: int}
     */
    public function toArray(): array
    {
        return ['requests' => $this->requests, 'per_seconds' => $this->perSeconds];
    }
}
