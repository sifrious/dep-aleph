<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\SpokenSound;

interface SpokenSoundObservationWriter
{
    public function write(SpokenSoundArtifactSubmission $submission, string $attemptId): string;
}
