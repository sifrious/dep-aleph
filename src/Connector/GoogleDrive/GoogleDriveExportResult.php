<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GoogleDrive;

final readonly class GoogleDriveExportResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $fileId,
        public string $revisionId,
        public string $sourceMimeType,
        public string $exportMediaType,
        public string $exportExtension,
        public string $filename,
        public string $contents,
        public bool $nativeGoogleFormat,
        public array $metadata = [],
    ) {}
}
