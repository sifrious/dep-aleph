<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Image;

/**
 * Stores an outsourced classification result as a derived observation/extraction.
 * This package does not classify images.
 */
final readonly class RecordImageClassification
{
    public function __construct(private ImageClassificationRecorder $recorder) {}

    public function record(ImageClassificationObservation $observation): void
    {
        $this->recorder->record($observation);
    }
}
