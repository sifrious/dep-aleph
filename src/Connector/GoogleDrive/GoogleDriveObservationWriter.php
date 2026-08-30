<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GoogleDrive;

interface GoogleDriveObservationWriter
{
    public function write(GoogleDriveArtifactSubmission $submission, string $attemptId): string;
}
