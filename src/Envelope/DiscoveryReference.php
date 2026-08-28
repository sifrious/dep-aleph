<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Envelope;

final readonly class DiscoveryReference
{
    public function __construct(
        public string $reference,
        public string $relationship = 'discovered',
    ) {}

    public static function from(self|string $value): self
    {
        return $value instanceof self ? $value : new self($value);
    }
}
