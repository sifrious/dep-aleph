<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Handwriting;

interface HandwritingObservationWriter
{
    public function write(HandwritingArtifactSubmission $submission, string $attemptId): string;
}
