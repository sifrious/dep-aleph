<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Testing\Fakes;

use Sifrious\Aleph\Connector\ConfigurationField;
use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\Connector;
use Sifrious\Aleph\Connector\Contracts\DiscoversSources;
use Sifrious\Aleph\Connector\Contracts\DownloadsArtifacts;
use Sifrious\Aleph\Connector\Values\Artifact;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;
use Sifrious\Aleph\Connector\Values\DiscoveredSource;
use Sifrious\Aleph\Connector\Values\DiscoveredSources;
use Sifrious\Aleph\Connector\Values\OperationRequest;

final class DiscoveryAndDownloadConnector implements Connector, DiscoversSources, DownloadsArtifacts
{
    public function id(): string
    {
        return 'archive-drop';
    }

    public function name(): string
    {
        return 'Archive Drop';
    }

    public function version(): string
    {
        return '2.1.0';
    }

    public function configuration(): ConfigurationSchema
    {
        return new ConfigurationSchema(
            ConfigurationField::text('base_url', 'Root of the archive to enumerate'),
            ConfigurationField::secret('api_token', 'Token used to authenticate downloads'),
            ConfigurationField::boolean('verify_checksums', 'Compare downloaded bytes against the manifest'),
        );
    }

    public function discoverSources(OperationRequest $request): DiscoveredSources
    {
        return new DiscoveredSources(
            new DiscoveredSource($request->sourceReference.'/2025', 'Archive 2025', ['files' => 3]),
            new DiscoveredSource($request->sourceReference.'/2026', 'Archive 2026', ['files' => 1]),
        );
    }

    public function downloadArtifact(ArtifactRequest $request): Artifact
    {
        return new Artifact(
            $request->artifactReference,
            'application/pdf',
            '%PDF-1.4 '.$request->artifactReference,
            ['source' => $request->sourceReference],
        );
    }
}
