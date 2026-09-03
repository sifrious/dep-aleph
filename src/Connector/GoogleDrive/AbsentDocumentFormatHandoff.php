<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GoogleDrive;

/**
 * Explicit fallback for hosts that disable document formatting.
 */
final class AbsentDocumentFormatHandoff implements DocumentFormatHandoff
{
    public function handOff(DocumentFormatHandoffRequest $request): DocumentFormatHandoffResult
    {
        return DocumentFormatHandoffResult::deferred([
            'reason' => 'document_format_formatter_not_bound',
            'formatter' => null,
            'artifact_reference' => $request->artifactReference,
            'accepted_observation_id' => $request->acceptedObservationId,
            'filename' => $request->filename,
            'media_type' => $request->mediaType,
            'sha256' => $request->checksum,
            'bytes' => $request->bytes,
            'drive_run_id' => $request->driveRunId,
        ]);
    }
}
