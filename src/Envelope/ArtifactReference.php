<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Envelope;

final readonly class ArtifactReference
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $reference,
        public string $relationship = 'attachment',
        public ?string $mediaType = null,
        public array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'reference' => $this->reference,
            'relationship' => $this->relationship,
            'media_type' => $this->mediaType,
            'metadata' => $this->metadata === [] ? null : $this->metadata,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
