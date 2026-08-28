<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Values;

final readonly class Artifact
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $reference,
        public string $mediaType,
        public string $contents,
        public array $metadata = [],
    ) {}

    public function bytes(): int
    {
        return strlen($this->contents);
    }
}
