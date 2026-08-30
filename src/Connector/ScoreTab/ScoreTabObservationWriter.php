<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\ScoreTab;

interface ScoreTabObservationWriter
{
    public function write(ScoreTabArtifactSubmission $submission, string $attemptId): string;
}
