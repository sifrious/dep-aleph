<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\VideoFile;

/**
 * Canonical Funes observation envelope shape for local video (MME-1223 / MME-771).
 *
 * PHP and the sibling Python twin both emit this document. Neither imports the other.
 */
final class VideoFileEnvelopeDocument
{
    public const CAPABILITY = 'video-file';

    public const SOURCE_NAME = 'local-video-file';

    public const CONNECTOR_VERSION = '1.0.0';

    public const EXTENSION_NAMESPACE = 'video.file';

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public static function fromSubmission(VideoFileArtifactSubmission $submission, string $language): array
    {
        return self::build(
            sourceReference: $submission->sourceReference,
            sourceInstallationId: $submission->sourceInstallationId,
            runId: $submission->runId,
            artifactReference: $submission->artifactReference,
            mediaType: $submission->mediaType,
            contents: $submission->contents,
            checksum: $submission->checksum,
            bytes: $submission->bytes,
            metadata: $submission->metadata,
            language: $language,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public static function build(
        string $sourceReference,
        string $sourceInstallationId,
        string $runId,
        string $artifactReference,
        string $mediaType,
        string $contents,
        string $checksum,
        int $bytes,
        array $metadata,
        string $language,
    ): array {
        $artifact = [
            'reference' => $artifactReference.'#media',
            'relationship' => 'primary',
            'media_type' => $mediaType,
            'metadata' => [
                'bytes' => $bytes,
                'sha256' => $checksum,
            ],
        ];
        $extension = [
            'namespace' => self::EXTENSION_NAMESPACE,
            'version' => 1,
            'data' => [
                'artifact_reference' => $artifactReference,
                'metadata' => $metadata,
                'checksum' => [
                    'algorithm' => 'sha256',
                    'value' => $checksum,
                    'bytes' => $bytes,
                ],
            ],
        ];
        $provenance = [
            'connector' => self::CAPABILITY,
            'connector_version' => self::CONNECTOR_VERSION,
            'installation' => $sourceInstallationId,
            'run' => $runId,
            'details' => [
                'artifact_reference' => $artifactReference,
                'language' => $language,
            ],
        ];

        return [
            'source_reference' => $sourceReference,
            'source_name' => self::SOURCE_NAME,
            'resource_reference' => $artifactReference,
            'content_type' => $mediaType,
            'payload_sha256' => $checksum,
            'payload_base64' => base64_encode($contents),
            'payload_bytes' => $bytes,
            'provenance' => $provenance,
            'artifacts' => [$artifact],
            'extensions' => [$extension],
            'aleph' => [
                'envelope_version' => 1,
                'artifacts' => [$artifact],
                'provenance' => $provenance,
            ],
        ];
    }

    /**
     * Shape keys used to compare PHP and Python twins without binding on timestamps.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function comparableShape(array $document): array
    {
        $provenance = is_array($document['provenance'] ?? null) ? $document['provenance'] : [];
        $details = is_array($provenance['details'] ?? null) ? $provenance['details'] : [];
        unset($details['language']);

        return [
            'source_name' => $document['source_name'] ?? null,
            'content_type' => $document['content_type'] ?? null,
            'payload_sha256' => $document['payload_sha256'] ?? null,
            'payload_bytes' => $document['payload_bytes'] ?? null,
            'provenance' => [
                'connector' => $provenance['connector'] ?? null,
                'connector_version' => $provenance['connector_version'] ?? null,
                'details' => [
                    'artifact_reference' => $details['artifact_reference'] ?? null,
                ],
            ],
            'artifacts' => $document['artifacts'] ?? null,
            'extensions' => $document['extensions'] ?? null,
            'aleph' => [
                'envelope_version' => is_array($document['aleph'] ?? null)
                    ? ($document['aleph']['envelope_version'] ?? null)
                    : null,
            ],
        ];
    }
}
