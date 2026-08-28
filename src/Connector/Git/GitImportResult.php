<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Git;

use Sifrious\Aleph\Ingestion\IngestionCheckpoint;

final readonly class GitImportResult
{
    /**
     * @param  list<string>  $acceptedReferences
     */
    public function __construct(
        public string $headSha,
        public bool $forcePushed,
        public int $commits,
        public int $files,
        public int $changes,
        public int $blameRanges,
        public array $acceptedReferences,
        public IngestionCheckpoint $checkpoint,
    ) {}

    /**
     * @return array<string, int|bool|string>
     */
    public function summary(): array
    {
        return [
            'head_sha' => $this->headSha,
            'force_pushed' => $this->forcePushed,
            'commits' => $this->commits,
            'files' => $this->files,
            'changes' => $this->changes,
            'blame_ranges' => $this->blameRanges,
            'accepted' => count($this->acceptedReferences),
            'checkpoint_version' => $this->checkpoint->version,
        ];
    }
}
