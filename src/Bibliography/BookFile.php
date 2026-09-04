<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Bibliography;

use DateTimeImmutable;

final readonly class BookFile
{
    /**
     * @param  list<SourceIdentifier>  $sourceIdentifiers
     * @param  array<string, mixed>  $acquisitionMetadata
     */
    public function __construct(
        public BookFileId $id,
        public EditionId $editionId,
        public ResourceId $resourceId,
        public ContentIdentity $contentIdentity,
        public string $mimeType,
        public ?string $format,
        public ?string $encoding,
        public array $sourceIdentifiers,
        public array $acquisitionMetadata,
        public ?DateTimeImmutable $acquiredAt,
        public ?BookFileId $derivedFromFileId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
