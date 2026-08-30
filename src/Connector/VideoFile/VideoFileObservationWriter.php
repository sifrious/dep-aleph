<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\VideoFile;

interface VideoFileObservationWriter
{
    public function write(VideoFileArtifactSubmission $submission, string $attemptId): string;
}
