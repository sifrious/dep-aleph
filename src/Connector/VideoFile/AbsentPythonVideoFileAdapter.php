<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\VideoFile;

use Sifrious\Aleph\Ingestion\LaunchRejected;

final class AbsentPythonVideoFileAdapter implements PythonVideoFileAdapter
{
    public function available(): bool
    {
        return false;
    }

    public function emitEnvelope(
        string $sourceReference,
        string $sourceInstallationId,
        string $runId,
        string $artifactReference,
        string $mediaType,
        string $contents,
        array $metadata,
    ): array {
        throw new LaunchRejected(
            'language_unavailable',
            'Ingest language [python] is not available for capability [video-file].',
        );
    }
}
