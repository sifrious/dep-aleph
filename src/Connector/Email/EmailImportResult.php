<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Email;

final readonly class EmailImportResult
{
    /** @param list<string> $acceptedReferences */
    public function __construct(
        public int $pages,
        public int $messages,
        public ?string $checkpoint,
        public bool $complete,
        public array $acceptedReferences,
    ) {}

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'pages' => $this->pages,
            'messages' => $this->messages,
            'checkpoint' => $this->checkpoint,
            'complete' => $this->complete,
            'accepted_references' => $this->acceptedReferences,
        ];
    }
}
