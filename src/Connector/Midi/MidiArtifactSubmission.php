<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Midi;

final readonly class MidiArtifactSubmission
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
        public array $metadata,
        public MidiParseResult $parse,
    ) {}
}
