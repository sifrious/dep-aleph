<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GoogleDrive;

use InvalidArgumentException;
use Sifrious\Aleph\Connector\Configuration\GoogleDriveConfigurationAdapter;
use Sifrious\Aleph\Connector\Configuration\GoogleDriveSourceConfigurator;
use Sifrious\Aleph\Connector\Configuration\SourceConfiguration;
use Sifrious\Aleph\Connector\Configuration\SourceConfigurationRecorder;
use Sifrious\Aleph\Connector\Configuration\SourceConfigurationRequest;
use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\Connector;
use Sifrious\Aleph\Connector\Contracts\ConfiguresSources;
use Sifrious\Aleph\Connector\Contracts\DownloadsArtifacts;
use Sifrious\Aleph\Connector\Values\Artifact;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;

final readonly class GoogleDriveConnector implements ConfiguresSources, Connector, DownloadsArtifacts
{
    public function __construct(
        private GoogleDriveFileClient $client,
        private ?SourceConfigurationRecorder $configurationRecorder = null,
    ) {}

    public function id(): string
    {
        return 'google-drive';
    }

    public function name(): string
    {
        return 'Google Drive';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function configuration(): ConfigurationSchema
    {
        return (new GoogleDriveConfigurationAdapter)->schema();
    }

    public function configureSource(SourceConfigurationRequest $request): SourceConfiguration
    {
        return (new GoogleDriveSourceConfigurator($this, $this->configurationRecorder))->configureSource($request);
    }

    public function downloadArtifact(ArtifactRequest $request): Artifact
    {
        $fileId = is_string($request->parameters['file_id'] ?? null) ? trim((string) $request->parameters['file_id']) : '';

        if ($fileId === '') {
            $fileId = $this->fileIdFromReference($request->artifactReference);
        }

        if ($fileId === '') {
            throw new InvalidArgumentException('Google Drive downloadArtifact requires a Drive file id.');
        }

        $preferred = is_string($request->parameters['preferred_extension'] ?? null)
            ? (string) $request->parameters['preferred_extension']
            : null;

        $export = $this->client->exportOrDownload($fileId, $preferred);

        return new Artifact(
            reference: $request->artifactReference !== ''
                ? $request->artifactReference
                : $this->artifactReference($export->fileId, $export->revisionId),
            mediaType: $export->exportMediaType,
            contents: $export->contents,
            metadata: array_merge([
                'source_reference' => $request->sourceReference,
                'file_id' => $export->fileId,
                'revision_id' => $export->revisionId,
                'source_mime_type' => $export->sourceMimeType,
                'export_extension' => $export->exportExtension,
                'filename' => $export->filename,
                'native_google_format' => $export->nativeGoogleFormat,
                'sha256' => hash('sha256', $export->contents),
                'bytes' => strlen($export->contents),
            ], $export->metadata),
        );
    }

    public static function artifactReference(string $fileId, string $revisionId): string
    {
        return sprintf('google-drive://file/%s/revision/%s', rawurlencode($fileId), rawurlencode($revisionId));
    }

    private function fileIdFromReference(string $reference): string
    {
        if (preg_match('#^google-drive://file/([^/]+)/revision/#', $reference, $matches) === 1) {
            return rawurldecode($matches[1]);
        }

        return '';
    }
}
