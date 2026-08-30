<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GoogleDrive;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Drive files.get / files.export client. Requires a bearer access token.
 * Hosts supply the token; this package does not implement OAuth dance.
 */
final readonly class HttpGoogleDriveFileClient implements GoogleDriveFileClient
{
    private const API = 'https://www.googleapis.com/drive/v3';

    public function __construct(
        private Factory $http,
        private string $accessToken,
    ) {
        if (trim($accessToken) === '') {
            throw new MissingGoogleDriveCredentials(
                'Google Drive credentials are not configured. Register an OAuth access token before ingesting Drive files.',
            );
        }
    }

    public function metadata(string $fileId): GoogleDriveFileMetadata
    {
        $id = $this->assertFileId($fileId);
        $response = $this->request('GET', self::API.'/files/'.rawurlencode($id), [
            'query' => [
                'fields' => 'id,name,mimeType,headRevisionId,modifiedTime,version',
                'supportsAllDrives' => 'true',
            ],
        ]);
        $this->assertSuccess($response, $id, metadata: true);
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new UnfetchableGoogleDriveFile('Google Drive metadata response was not a JSON object.');
        }

        $revision = is_string($payload['headRevisionId'] ?? null) && trim((string) $payload['headRevisionId']) !== ''
            ? (string) $payload['headRevisionId']
            : (is_string($payload['version'] ?? null) && trim((string) $payload['version']) !== ''
                ? (string) $payload['version']
                : (is_string($payload['modifiedTime'] ?? null) ? (string) $payload['modifiedTime'] : ''));

        if ($revision === '') {
            throw new UnfetchableGoogleDriveFile('Google Drive metadata did not include a revision identity.');
        }

        $mime = is_string($payload['mimeType'] ?? null) ? (string) $payload['mimeType'] : '';
        $name = is_string($payload['name'] ?? null) ? (string) $payload['name'] : $id;

        if ($mime === '') {
            throw new UnfetchableGoogleDriveFile('Google Drive metadata did not include a MIME type.');
        }

        return new GoogleDriveFileMetadata(
            fileId: is_string($payload['id'] ?? null) ? (string) $payload['id'] : $id,
            revisionId: $revision,
            mimeType: $mime,
            name: $name,
            raw: $payload,
        );
    }

    public function exportOrDownload(string $fileId, ?string $preferredExtension = null): GoogleDriveExportResult
    {
        $meta = $this->metadata($fileId);
        $plan = GoogleDriveExportPlan::for($meta->mimeType, $preferredExtension);
        $filename = $this->interchangeFilename($meta->name, $plan['extension'], $plan['export']);

        if ($plan['export']) {
            $contents = $this->exportBytes($meta->fileId, $plan['media_type']);
        } else {
            $contents = $this->downloadBytes($meta->fileId);
        }

        if ($contents === '') {
            throw new GoogleDriveExportDenied(
                sprintf(
                    'Google Drive returned empty bytes for file [%s] (mime [%s]). Missing export permission or empty export is treated as failure, not an empty artifact.',
                    $meta->fileId,
                    $meta->mimeType,
                ),
            );
        }

        return new GoogleDriveExportResult(
            fileId: $meta->fileId,
            revisionId: $meta->revisionId,
            sourceMimeType: $meta->mimeType,
            exportMediaType: $plan['media_type'],
            exportExtension: $plan['extension'],
            filename: $filename,
            contents: $contents,
            nativeGoogleFormat: GoogleDriveExportPlan::isNativeGoogleFormat($meta->mimeType),
            metadata: [
                'name' => $meta->name,
                'export' => $plan['export'],
                'preferred_extension' => $preferredExtension,
            ],
        );
    }

    private function exportBytes(string $fileId, string $exportMimeType): string
    {
        $response = $this->request('GET', self::API.'/files/'.rawurlencode($fileId).'/export', [
            'query' => [
                'mimeType' => $exportMimeType,
            ],
        ]);
        $this->assertSuccess($response, $fileId, metadata: false, export: true);

        return $response->body();
    }

    private function downloadBytes(string $fileId): string
    {
        $response = $this->request('GET', self::API.'/files/'.rawurlencode($fileId), [
            'query' => [
                'alt' => 'media',
                'supportsAllDrives' => 'true',
            ],
        ]);
        $this->assertSuccess($response, $fileId, metadata: false, export: false);

        return $response->body();
    }

    /**
     * @param  array{query?: array<string, string>}  $options
     */
    private function request(string $method, string $url, array $options = []): Response
    {
        try {
            $pending = $this->http
                ->withToken($this->accessToken)
                ->withHeaders(['User-Agent' => 'AlephGoogleDriveIngestion/1.0', 'Accept' => '*/*'])
                ->timeout(60)
                ->connectTimeout(5);

            if ($method === 'GET') {
                return $pending->get($url, $options['query'] ?? []);
            }

            throw new UnfetchableGoogleDriveFile('Unsupported Google Drive HTTP method.');
        } catch (MissingGoogleDriveCredentials|GoogleDriveExportDenied|UnfetchableGoogleDriveFile $failure) {
            throw $failure;
        } catch (ConnectionException $failure) {
            throw new UnfetchableGoogleDriveFile($failure->getMessage(), previous: $failure);
        } catch (Throwable $failure) {
            throw new UnfetchableGoogleDriveFile($failure->getMessage(), previous: $failure);
        }
    }

    private function assertSuccess(Response $response, string $fileId, bool $metadata, bool $export = false): void
    {
        $status = $response->status();

        if ($status === 401 || $status === 403) {
            $message = $this->apiErrorMessage($response) ?? sprintf('Google Drive refused access to file [%s] with HTTP %d.', $fileId, $status);

            if ($export || (! $metadata && $status === 403)) {
                throw new GoogleDriveExportDenied($message);
            }

            throw new MissingGoogleDriveCredentials($message);
        }

        if ($status === 404) {
            throw new UnfetchableGoogleDriveFile(sprintf('Google Drive file [%s] was not found.', $fileId));
        }

        if ($response->failed()) {
            throw new UnfetchableGoogleDriveFile(
                $this->apiErrorMessage($response) ?? sprintf('Google Drive request failed with HTTP %d.', $status),
            );
        }
    }

    private function apiErrorMessage(Response $response): ?string
    {
        $json = $response->json();

        if (! is_array($json)) {
            return null;
        }

        $error = $json['error'] ?? null;

        if (is_array($error) && is_string($error['message'] ?? null)) {
            return (string) $error['message'];
        }

        return null;
    }

    private function assertFileId(string $fileId): string
    {
        $id = trim($fileId);

        if ($id === '') {
            throw new UnfetchableGoogleDriveFile('A Google Drive file id is required.');
        }

        return $id;
    }

    private function interchangeFilename(string $name, string $extension, bool $exported): string
    {
        $base = trim($name) === '' ? 'drive-file' : $name;

        if (! $exported) {
            return $base;
        }

        $stem = pathinfo($base, PATHINFO_FILENAME);
        $stem = is_string($stem) && $stem !== '' ? $stem : $base;

        return $stem.'.'.$extension;
    }
}
