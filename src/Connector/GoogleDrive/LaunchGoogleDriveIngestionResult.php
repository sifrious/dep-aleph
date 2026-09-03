<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GoogleDrive;

final readonly class LaunchGoogleDriveIngestionResult
{
    /**
     * @param  list<string>  $acceptedReferences
     * @param  array<string, mixed>  $formatHandoff
     */
    public function __construct(
        public string $runId,
        public bool $replayed,
        public string $fileId,
        public string $revisionId,
        public string $artifactReference,
        public string $exportMediaType,
        public string $exportExtension,
        public array $acceptedReferences,
        public array $formatHandoff = [],
    ) {}
}
