<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\SpokenSound;

final readonly class SpokenSoundArtifactSubmission
{
    /**
     * @param  array<string, mixed>  $containerMetadata
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
        public ?float $durationSeconds,
        public array $containerMetadata,
    ) {}
}
