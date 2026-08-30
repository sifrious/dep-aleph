<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\YouTube;

interface YouTubeObservationWriter
{
    public function write(YouTubeArtifactSubmission $submission, string $attemptId): string;
}
