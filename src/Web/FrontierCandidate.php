<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

final readonly class FrontierCandidate
{
    public function __construct(
        public int $id,
        public CanonicalUrl $url,
        public string $requestedUrl,
        public int $depth,
        public DiscoveryOrigin $origin,
        public ?int $parentId,
    ) {}
}
