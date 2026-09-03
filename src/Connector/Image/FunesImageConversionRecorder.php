<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Image;

use Sifrious\Funes\Persistence\ObservationStore;
use Sifrious\Funes\Value\ExtractionDraft;
use Sifrious\Funes\Value\IngestionRun as FunesIngestionRun;
use Sifrious\Funes\Value\Producer;
use Sifrious\Funes\Value\ProducerContext;

/**
 * Stores converted image formats as versioned Funes extracted representations (MME-1438).
 * Re-convert records another version; raw observation bytes remain authoritative.
 */
final readonly class FunesImageConversionRecorder implements ImageConversionRecorder
{
    public function __construct(private ObservationStore $observations) {}

    public function record(string $observationId, ImageConversion $conversion, string $sourceChecksum, string $runId): void
    {
        $this->observations->recordExtraction(new ExtractionDraft(
            observationId: $observationId,
            extractor: 'aleph.image.'.$conversion->converterName,
            version: $conversion->converterVersion.'/'.$conversion->targetFormat,
            producerContext: new ProducerContext(
                new Producer(
                    'aleph:image/converter/'.$conversion->converterName,
                    'Aleph image converter '.$conversion->converterName,
                ),
                new FunesIngestionRun($runId),
            ),
            result: $conversion->toExtractionResult($sourceChecksum),
        ));
    }
}
