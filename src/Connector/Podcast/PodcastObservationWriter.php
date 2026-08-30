<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Podcast;

interface PodcastObservationWriter
{
    public function write(PodcastArtifactSubmission $submission, string $attemptId): string;
}
