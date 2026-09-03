<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GoogleDrive;

/**
 * Default Drive client when no OAuth secrets are configured.
 * The public package must work without a Drive account.
 */
final class NullGoogleDriveFileClient implements GoogleDriveFileClient
{
    public function metadata(string $fileId): GoogleDriveFileMetadata
    {
        throw new MissingGoogleDriveCredentials(
            'Google Drive credentials are not configured. Register an OAuth access token before ingesting Drive files.',
        );
    }

    public function exportOrDownload(string $fileId, ?string $preferredExtension = null): GoogleDriveExportResult
    {
        throw new MissingGoogleDriveCredentials(
            'Google Drive credentials are not configured. Register an OAuth access token before ingesting Drive files.',
        );
    }
}
