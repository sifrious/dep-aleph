<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Bibliography;

use DateTimeImmutable;

final readonly class Book
{
    /**
     * @param  list<SourceIdentifier>  $identifiers
     */
    public function __construct(
        public BookId $id,
        public SourceIdentifier $sourceIdentifier,
        public ?string $title,
        public ?string $language,
        public array $identifiers,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
