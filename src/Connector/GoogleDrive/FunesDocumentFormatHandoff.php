<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GoogleDrive;

use Sifrious\Funes\Persistence\ObservationStore;
use Sifrious\Funes\Value\ExtractionDraft;
use Sifrious\Funes\Value\IngestionRun as FunesIngestionRun;
use Sifrious\Funes\Value\Producer;
use Sifrious\Funes\Value\ProducerContext;

final readonly class FunesDocumentFormatHandoff implements DocumentFormatHandoff
{
    public function __construct(
        private DocumentFormatter $formatter,
        private ObservationStore $observations,
    ) {}

    public function handOff(DocumentFormatHandoffRequest $request): DocumentFormatHandoffResult
    {
        if (! $this->formatter->supports($request->mediaType)) {
            return DocumentFormatHandoffResult::deferred([
                'reason' => 'document_format_not_supported',
                'media_type' => $request->mediaType,
                'artifact_reference' => $request->artifactReference,
            ]);
        }

        $document = $this->formatter->format($request);
        $extraction = $this->observations->recordExtraction(new ExtractionDraft(
            observationId: $request->acceptedObservationId,
            extractor: $document->formatter,
            version: $document->version,
            producerContext: new ProducerContext(
                new Producer('aleph:document/local', 'Aleph local document formatter'),
                new FunesIngestionRun($request->driveRunId),
            ),
            result: $document->extractionResult($request),
        ));

        return DocumentFormatHandoffResult::launched($extraction->id, [
            'formatter' => $document->formatter,
            'version' => $document->version,
            'sha256' => $request->checksum,
            'extraction_id' => $extraction->id,
        ]);
    }
}
