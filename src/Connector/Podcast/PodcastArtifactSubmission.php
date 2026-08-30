<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Podcast;

final readonly class PodcastArtifactSubmission
{
    /**
     * @param  array<string, mixed>  $episodeMetadata
     */
    public function __construct(
        public string $sourceReference,
        public string $sourceInstallationId,
        public string $runId,
        public string $episodeIdentity,
        public string $enclosureUrl,
        public string $mediaType,
        public string $contents,
        public string $checksum,
        public int $bytes,
        public array $episodeMetadata,
    ) {}
}
