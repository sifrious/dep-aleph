<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Testing\Fakes;

use Sifrious\Aleph\Connector\Contracts\DownloadsArtifacts;
use Sifrious\Aleph\Connector\Values\Artifact;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;

final class DownloadConnector extends BaseFakeConnector implements DownloadsArtifacts
{
    /** @var list<ArtifactRequest> */
    public array $downloadCalls = [];

    public function downloadArtifact(ArtifactRequest $request): Artifact
    {
        $this->downloadCalls[] = $request;

        return new Artifact(
            $request->artifactReference,
            'text/plain',
            'contents of '.$request->artifactReference,
        );
    }
}
