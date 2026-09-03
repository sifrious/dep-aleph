<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\VideoFile;

/**
 * Sibling Python twin port. Not a Composer dependency; missing Python is an explicit refuse.
 */
interface PythonVideoFileAdapter
{
    public function available(): bool;

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function emitEnvelope(
        string $sourceReference,
        string $sourceInstallationId,
        string $runId,
        string $artifactReference,
        string $mediaType,
        string $contents,
        array $metadata,
    ): array;
}
