<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GoogleDrive;

final readonly class GoogleDriveFileMetadata
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $fileId,
        public string $revisionId,
        public string $mimeType,
        public string $name,
        public array $raw = [],
    ) {}
}
