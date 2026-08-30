<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\YouTube;

final readonly class YouTubeArtifactSubmission
{
    /**
     * @param  array<string, mixed>  $videoMetadata
     * @param  array<string, mixed>|null  $transcript
     */
    public function __construct(
        public string $sourceReference,
        public string $sourceInstallationId,
        public string $runId,
        public string $canonicalUrl,
        public string $mediaType,
        public string $contents,
        public string $checksum,
        public int $bytes,
        public array $videoMetadata,
        public ?array $transcript,
    ) {}
}
