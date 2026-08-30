<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GoogleDrive;

/**
 * Clear handoff into the existing MME-777 / MME-820 document format tools.
 * Drive stores interchange bytes; it does not extract markdown/HTML/PDF/Word itself.
 */
final readonly class DocumentFormatHandoffRequest
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $sourceReference,
        public string $sourceInstallationId,
        public string $driveRunId,
        public string $acceptedObservationId,
        public string $artifactReference,
        public string $filename,
        public string $mediaType,
        public string $contents,
        public string $checksum,
        public int $bytes,
        public array $metadata = [],
    ) {}
}
