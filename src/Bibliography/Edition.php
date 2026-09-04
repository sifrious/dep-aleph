<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Bibliography;

use DateTimeImmutable;

final readonly class Edition
{
    /**
     * @param  list<SourceIdentifier>  $identifiers
     */
    public function __construct(
        public EditionId $id,
        public BookId $bookId,
        public SourceIdentifier $sourceIdentifier,
        public ?string $title,
        public ?string $language,
        public ?string $publisher,
        public ?string $publishedAt,
        public array $identifiers,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
