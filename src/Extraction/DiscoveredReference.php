<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Extraction;

final readonly class DiscoveredReference
{
    public function __construct(
        public string $reference,
        public DiscoveryRelationship $relationship,
    ) {}
}
