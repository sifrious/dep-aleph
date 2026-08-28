<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Testing\Fakes;

use DateTimeImmutable;
use Sifrious\Aleph\Connector\Contracts\Backfills;
use Sifrious\Aleph\Connector\Contracts\ChecksHealth;
use Sifrious\Aleph\Connector\Contracts\DiscoversSources;
use Sifrious\Aleph\Connector\Contracts\DownloadsArtifacts;
use Sifrious\Aleph\Connector\Contracts\ExtractsContent;
use Sifrious\Aleph\Connector\Contracts\Normalizes;
use Sifrious\Aleph\Connector\Values\Artifact;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;
use Sifrious\Aleph\Connector\Values\DiscoveredSource;
use Sifrious\Aleph\Connector\Values\DiscoveredSources;
use Sifrious\Aleph\Connector\Values\ExtractedContent;
use Sifrious\Aleph\Connector\Values\HealthReport;
use Sifrious\Aleph\Connector\Values\NormalizedRecord;
use Sifrious\Aleph\Connector\Values\OperationRequest;
use Sifrious\Aleph\Connector\Values\OperationResult;
use Sifrious\Aleph\Connector\Values\RawRecord;

final class CompositeConnector extends BaseFakeConnector implements Backfills, ChecksHealth, DiscoversSources, DownloadsArtifacts, ExtractsContent, Normalizes
{
    public function discoverSources(OperationRequest $request): DiscoveredSources
    {
        return new DiscoveredSources(new DiscoveredSource($request->sourceReference.'/one', 'One'));
    }

    public function backfill(OperationRequest $request): OperationResult
    {
        return OperationResult::completed(10, ['source' => $request->sourceReference]);
    }

    public function downloadArtifact(ArtifactRequest $request): Artifact
    {
        return new Artifact($request->artifactReference, 'application/json', '{}');
    }

    public function extractContent(Artifact $artifact): ExtractedContent
    {
        return new ExtractedContent($artifact->reference, $artifact->contents);
    }

    public function normalize(RawRecord $record): NormalizedRecord
    {
        return new NormalizedRecord(
            $record->sourceReference,
            $record->identifier,
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            $record->payload,
        );
    }

    public function checkHealth(): HealthReport
    {
        return HealthReport::healthy();
    }
}
