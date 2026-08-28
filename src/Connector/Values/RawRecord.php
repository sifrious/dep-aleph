<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Values;

final readonly class RawRecord
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $sourceReference,
        public string $identifier,
        public array $payload = [],
    ) {}
}
