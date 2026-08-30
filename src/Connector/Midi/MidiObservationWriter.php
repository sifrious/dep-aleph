<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Midi;

interface MidiObservationWriter
{
    public function write(MidiArtifactSubmission $submission, string $attemptId): string;
}
