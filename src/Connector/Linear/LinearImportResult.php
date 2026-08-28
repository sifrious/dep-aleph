<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Linear;

final readonly class LinearImportResult
{
    /**
     * @param  array<string, int>  $pages
     * @param  array<string, int>  $activities
     * @param  array<string, ?string>  $cursors
     * @param  list<string>  $acceptedReferences
     */
    public function __construct(
        public array $pages,
        public array $activities,
        public array $cursors,
        public array $acceptedReferences,
    ) {}

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'pages' => $this->pages,
            'activities' => $this->activities,
            'cursors' => $this->cursors,
            'accepted_references' => $this->acceptedReferences,
        ];
    }
}
