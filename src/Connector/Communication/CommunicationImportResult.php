<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Communication;

final readonly class CommunicationImportResult
{
    /** @param list<string> $acceptedReferences */
    public function __construct(
        public int $pages,
        public int $records,
        public ?string $checkpoint,
        public bool $complete,
        public array $acceptedReferences,
    ) {}

    /** @return array<string, int|string|null> */
    public function summary(): array
    {
        return ['pages' => $this->pages, 'records' => $this->records, 'checkpoint' => $this->checkpoint];
    }
}
