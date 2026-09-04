<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Bibliography;

use DateTimeImmutable;

final readonly class Author
{
    /**
     * @param  list<SourceIdentifier>  $identifiers
     */
    public function __construct(
        public AuthorId $id,
        public SourceIdentifier $sourceIdentifier,
        public ?string $name,
        public array $identifiers,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
