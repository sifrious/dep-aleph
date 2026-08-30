<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GoogleDrive;

/**
 * Default when MME-777 format LaunchIngestion is not bound in this package.
 * Records an explicit deferred handoff; does not extract or invent a second ingest API.
 */
final class AbsentDocumentFormatHandoff implements DocumentFormatHandoff
{
    public function handOff(DocumentFormatHandoffRequest $request): DocumentFormatHandoffResult
    {
        return DocumentFormatHandoffResult::deferred([
            'reason' => 'document_format_formatter_not_bound',
            'formatter' => 'mme-777',
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
