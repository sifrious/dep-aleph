<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GoogleDrive;

final readonly class GoogleDriveArtifactSubmission
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $sourceReference,
        public string $sourceInstallationId,
        public string $runId,
        public string $artifactReference,
        public string $fileId,
        public string $revisionId,
        public string $sourceMimeType,
        public string $mediaType,
        public string $filename,
        public string $contents,
        public string $checksum,
        public int $bytes,
        public bool $nativeGoogleFormat,
        public array $metadata,
    ) {}
}
