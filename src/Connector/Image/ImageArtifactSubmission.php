<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Image;

final readonly class ImageArtifactSubmission
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $sourceReference,
        public string $sourceInstallationId,
        public string $runId,
        public string $artifactReference,
        public string $mediaType,
        public string $contents,
        public string $checksum,
        public int $bytes,
        public ImageMetadata $image,
        public array $metadata,
    ) {}
}
