<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Values;

final readonly class ExtractedContent
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $reference,
        public ?string $text,
        public array $metadata = [],
        public ?string $error = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->error === null;
    }
}
