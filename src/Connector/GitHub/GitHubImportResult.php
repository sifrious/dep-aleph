<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GitHub;

use Sifrious\Aleph\Ingestion\IngestionCheckpoint;

final readonly class GitHubImportResult
{
    /**
     * @param  list<string>  $acceptedReferences
     */
    public function __construct(
        public int $pages,
        public int $activities,
        public ?string $cursor,
        public array $acceptedReferences,
        public ?IngestionCheckpoint $checkpoint,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'pages' => $this->pages,
            'activities' => $this->activities,
            'cursor' => $this->cursor,
            'accepted' => count($this->acceptedReferences),
        ];
    }
}
