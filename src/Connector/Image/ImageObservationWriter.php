<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Image;

interface ImageObservationWriter
{
    public function write(ImageArtifactSubmission $submission, string $attemptId): string;
}
