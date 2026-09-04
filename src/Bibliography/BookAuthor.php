<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Bibliography;

use DateTimeImmutable;

final readonly class BookAuthor
{
    public function __construct(
        public BookAuthorId $id,
        public BookId $bookId,
        public AuthorId $authorId,
        public string $role,
        public ?int $position,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
