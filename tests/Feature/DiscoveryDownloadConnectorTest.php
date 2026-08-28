<?php

declare(strict_types=1);

use Sifrious\Aleph\Connector\Capability;
use Sifrious\Aleph\Connector\ConnectorDispatcher;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\Contracts\ChecksHealth;
use Sifrious\Aleph\Connector\Rejection;
use Sifrious\Aleph\Connector\Values\Artifact;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;
use Sifrious\Aleph\Connector\Values\DiscoveredSources;
use Sifrious\Aleph\Connector\Values\OperationRequest;
use Sifrious\Aleph\Testing\Contracts\ConnectorContract;
use Sifrious\Aleph\Testing\Fakes\DiscoveryAndDownloadConnector;

beforeEach(function (): void {
    $this->registry = app(ConnectorRegistry::class);
    $this->registry->register(new DiscoveryAndDownloadConnector);
    $this->dispatcher = app(ConnectorDispatcher::class);
});

it('registers through the container and is recognised', function (): void {
    expect($this->registry->has('archive-drop'))->toBeTrue()
        ->and($this->registry->ids())->toContain('archive-drop');
});

it('performs discovery', function (): void {
    $result = $this->dispatcher->dispatch(
        'archive-drop',
        Capability::DiscoversSources,
        new OperationRequest('archive-drop:root'),
    );

    expect($result)->toBeInstanceOf(DiscoveredSources::class)
        ->and($result)->toHaveCount(2)
        ->and($result->references())->toBe(['archive-drop:root/2025', 'archive-drop:root/2026']);
});

it('performs artifact download', function (): void {
    $artifact = $this->dispatcher->dispatch(
        'archive-drop',
        Capability::DownloadsArtifacts,
        new ArtifactRequest('archive-drop:root/2025', 'minutes.pdf'),
    );

    expect($artifact)->toBeInstanceOf(Artifact::class)
        ->and($artifact->mediaType)->toBe('application/pdf')
        ->and($artifact->bytes())->toBeGreaterThan(0);
});

it('reports exactly those two capabilities in its manifest', function (): void {
    $manifest = $this->registry->manifest('archive-drop');

    expect($manifest->capabilityIds())->toBe(['sources.discover', 'artifacts.download'])
        ->and($manifest->availableOperations())->toBe(['sources.discover', 'artifacts.download']);
});

it('does not offer backfill, incremental sync, webhooks or health', function (): void {
    $unsupported = [
        Capability::Backfills,
        Capability::SyncsIncrementally,
        Capability::ConsumesWebhooks,
        Capability::ChecksHealth,
        Capability::UsesAgents,
        Capability::ExtractsContent,
        Capability::Normalizes,
    ];

    foreach ($unsupported as $capability) {
        expect($this->dispatcher->supports('archive-drop', $capability))->toBeFalse()
            ->and($this->registry->manifest('archive-drop')->supports($capability))->toBeFalse()
            ->and($this->dispatcher->rejectionFor('archive-drop', $capability)->reason)
            ->toBe(Rejection::CAPABILITY_NOT_SUPPORTED);
    }
});

it('does not assume health checking', function (): void {
    expect(new DiscoveryAndDownloadConnector)
        ->not->toBeInstanceOf(ChecksHealth::class);
});

it('needs no empty or unsupported-operation methods', function (): void {
    $methods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(DiscoveryAndDownloadConnector::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    expect($methods)->toBe(['id', 'name', 'version', 'configuration', 'discoverSources', 'downloadArtifact']);

    $source = (string) file_get_contents(
        (new ReflectionClass(DiscoveryAndDownloadConnector::class))->getFileName()
    );

    expect($source)->not->toContain('UnsupportedOperation')
        ->and($source)->not->toContain('throw new BadMethodCall');
});

it('satisfies the published connector contract', function (): void {
    $connector = new DiscoveryAndDownloadConnector;

    expect(ConnectorContract::violations($connector))->toBe([])
        ->and(ConnectorContract::probeAll($connector))->toBe([]);
});

it('lets a display layer derive its actions without provider knowledge', function (): void {
    $actions = [];

    foreach ($this->registry->manifests() as $manifest) {
        $actions[$manifest->id] = $manifest->availableOperations();
    }

    expect($actions)->toBe(['archive-drop' => ['sources.discover', 'artifacts.download']]);
});
