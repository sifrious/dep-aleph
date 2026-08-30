<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Podcast;

use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\Connector;
use Sifrious\Aleph\Connector\Contracts\DownloadsArtifacts;
use Sifrious\Aleph\Connector\Values\Artifact;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;

final readonly class PodcastConnector implements Connector, DownloadsArtifacts
{
    public function __construct(private PodcastEnclosureDownloader $downloader) {}

    public function id(): string
    {
        return 'podcast';
    }

    public function name(): string
    {
        return 'Podcast';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function configuration(): ConfigurationSchema
    {
        return new ConfigurationSchema;
    }

    public function downloadArtifact(ArtifactRequest $request): Artifact
    {
        $download = $this->downloader->download($request->artifactReference);

        return new Artifact(
            reference: $request->artifactReference,
            mediaType: $download->mediaType,
            contents: $download->contents,
            metadata: array_merge(
                [
                    'source_reference' => $request->sourceReference,
                    'enclosure_url' => $request->artifactReference,
                ],
                $download->metadata,
            ),
        );
    }
}
