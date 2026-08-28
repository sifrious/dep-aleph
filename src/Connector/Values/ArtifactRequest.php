<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Values;

final readonly class ArtifactRequest
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public string $sourceReference,
        public string $artifactReference,
        public array $parameters = [],
    ) {}
}
