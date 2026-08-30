<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\VideoFile;

interface VideoFileObservationWriter
{
    public function write(VideoFileArtifactSubmission $submission, string $attemptId): string;

    /**
     * Accept a pre-built envelope document (e.g. from the Python twin).
     *
     * @param  array<string, mixed>  $document
     */
    public function writeEnvelopeDocument(array $document, string $attemptId): string;
}
