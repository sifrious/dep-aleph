<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Bibliography;

use DateTimeImmutable;

final readonly class Resource
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<SourceIdentifier>  $identifiers
     */
    public function __construct(
        public ResourceId $id,
        public SourceIdentifier $sourceIdentifier,
        public string $resourceType,
        public ?string $canonicalUri,
        public ?string $language,
        public array $metadata,
        public array $identifiers,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
