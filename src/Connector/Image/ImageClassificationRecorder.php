<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Image;

interface ImageClassificationRecorder
{
    public function record(ImageClassificationObservation $observation): void;
}
