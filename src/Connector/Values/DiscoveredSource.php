<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Values;

final readonly class DiscoveredSource
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $reference,
        public string $name,
        public array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reference' => $this->reference,
            'name' => $this->name,
            'metadata' => $this->metadata,
        ];
    }
}
