<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Image;

use Sifrious\Funes\Persistence\ObservationStore;
use Sifrious\Funes\Value\ExtractionDraft;
use Sifrious\Funes\Value\IngestionRun as FunesIngestionRun;
use Sifrious\Funes\Value\Producer;
use Sifrious\Funes\Value\ProducerContext;

/**
 * Stores outsourced classification results as derived Funes extracted representations.
 * This package never runs a classifier; it only persists results that come back later.
 */
final readonly class FunesImageClassificationRecorder implements ImageClassificationRecorder
{
    public function __construct(private ObservationStore $observations) {}

    public function record(ImageClassificationObservation $observation): void
    {
        $this->observations->recordExtraction(new ExtractionDraft(
            observationId: $observation->observationId,
            extractor: 'aleph.image.classifier.'.$observation->classifierName,
            version: $observation->classifierVersion,
            producerContext: new ProducerContext(
                new Producer(
                    'aleph:image/classifier/'.$observation->classifierName,
                    'Outsourced image classifier '.$observation->classifierName,
                ),
                new FunesIngestionRun($observation->runId),
            ),
            result: $observation->toExtractionResult(),
        ));
    }
}
