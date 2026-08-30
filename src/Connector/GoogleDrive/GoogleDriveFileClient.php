<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GoogleDrive;

interface GoogleDriveFileClient
{
    public function metadata(string $fileId): GoogleDriveFileMetadata;

    public function exportOrDownload(string $fileId, ?string $preferredExtension = null): GoogleDriveExportResult;
}
