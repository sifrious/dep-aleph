<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Values;

use DateTimeImmutable;

final readonly class NormalizedRecord
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $provenance
     */
    public function __construct(
        public string $sourceReference,
        public string $identifier,
        public ?DateTimeImmutable $occurredAt,
        public array $attributes = [],
        public array $provenance = [],
    ) {}
}
