<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Handwriting;

use Sifrious\Funes\Persistence\ObservationStore;
use Sifrious\Funes\Value\ExtractionDraft;
use Sifrious\Funes\Value\IngestionRun as FunesIngestionRun;
use Sifrious\Funes\Value\Producer;
use Sifrious\Funes\Value\ProducerContext;

/**
 * Stores optional local OCR derivations as versioned Funes extracted representations.
 * Does not overwrite raw handwritten image bytes.
 */
final readonly class FunesHandwritingOcrDerivationRecorder implements HandwritingOcrDerivationRecorder
{
    public function __construct(private ObservationStore $observations) {}

    public function record(string $observationId, HandwritingOcrDerivation $derivation, string $runId): void
    {
        $this->observations->recordExtraction(new ExtractionDraft(
            observationId: $observationId,
            extractor: $derivation->modelName,
            version: $derivation->modelVersion,
            producerContext: new ProducerContext(
                new Producer(
                    'aleph:handwriting/local-ocr/'.$derivation->modelName,
                    'Aleph handwriting local OCR '.$derivation->modelName,
                ),
                new FunesIngestionRun($runId),
            ),
            result: [
                'kind' => 'handwriting_ocr_derived_representation',
                'model' => [
                    'name' => $derivation->modelName,
                    'version' => $derivation->modelVersion,
                ],
                'representation' => $derivation->representation(),
            ],
        ));
    }
}
