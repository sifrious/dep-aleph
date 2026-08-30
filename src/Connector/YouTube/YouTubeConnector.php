<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\YouTube;

use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\Connector;
use Sifrious\Aleph\Connector\Contracts\DownloadsArtifacts;
use Sifrious\Aleph\Connector\Values\Artifact;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;

final readonly class YouTubeConnector implements Connector, DownloadsArtifacts
{
    public function __construct(private YouTubeDownloader $downloader) {}

    public function id(): string
    {
        return 'youtube';
    }

    public function name(): string
    {
        return 'YouTube';
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
        $canonical = YouTubeCanonicalUrl::from($request->artifactReference);
        $download = $this->downloader->download($canonical);

        return new Artifact(
            reference: $canonical->value,
            mediaType: $download->mediaType,
            contents: $download->contents,
            metadata: array_filter([
                'canonical_url' => $canonical->value,
                'source_reference' => $request->sourceReference,
                'video' => $download->metadata,
                'transcript' => $download->transcript === null
                    ? null
                    : [
                        'media_type' => $download->transcript->mediaType,
                        'language' => $download->transcript->language,
                        'bytes' => strlen($download->transcript->contents),
                        'sha256' => hash('sha256', $download->transcript->contents),
                    ],
            ], static fn (mixed $value): bool => $value !== null);
    }
}
