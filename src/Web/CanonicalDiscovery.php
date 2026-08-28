<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

use Sifrious\Aleph\Extraction\DiscoveryRelationship;

final readonly class CanonicalDiscovery
{
    public function __construct(
        public CanonicalUrl $url,
        public DiscoveryRelationship $relationship,
    ) {}
}
