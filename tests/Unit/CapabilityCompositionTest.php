<?php

declare(strict_types=1);

use Sifrious\Aleph\Connector\Capability;
use Sifrious\Aleph\Connector\CapabilitySet;
use Sifrious\Aleph\Connector\ConnectorManifest;
use Sifrious\Aleph\Testing\Fakes\CompositeConnector;
use Sifrious\Aleph\Testing\Fakes\DiscoveryConnector;
use Sifrious\Aleph\Testing\Fakes\MinimalConnector;

it('maps every capability to exactly one contract', function (): void {
    $contracts = array_map(
        static fn (Capability $capability): string => $capability->contract(),
        Capability::cases(),
    );

    expect($contracts)->toHaveCount(count(array_unique($contracts)));

    foreach (Capability::cases() as $capability) {
        expect(interface_exists($capability->contract()))->toBeTrue()
            ->and(Capability::forContract($capability->contract()))->toBe($capability);
    }
});

it('derives a single capability from the interface a connector implements', function (): void {
    $set = CapabilitySet::of(new DiscoveryConnector('one'));

    expect($set->all())->toBe([Capability::DiscoversSources])
        ->and($set->has(Capability::DiscoversSources))->toBeTrue()
        ->and($set->has(Capability::Backfills))->toBeFalse();
});

it('derives several capabilities from a composite connector', function (): void {
    $set = CapabilitySet::of(new CompositeConnector('many'));

    expect($set->ids())->toContain('sources.discover', 'history.backfill', 'artifacts.download', 'content.extract')
        ->and($set->has(Capability::SyncsIncrementally))->toBeFalse()
        ->and($set->has(Capability::ConsumesWebhooks))->toBeFalse();
});

it('allows a connector that implements no capability at all', function (): void {
    $set = CapabilitySet::of(new MinimalConnector('bare'));

    expect($set->all())->toBe([])->and($set)->toHaveCount(0);
});

it('cannot report a capability the connector does not implement', function (): void {
    $manifest = ConnectorManifest::for(new DiscoveryConnector('one'));

    foreach (Capability::cases() as $capability) {
        $contract = $capability->contract();
        expect($manifest->supports($capability))->toBe(new DiscoveryConnector('one') instanceof $contract);
    }
});

it('separates dispatchable operations from participatory capabilities', function (): void {
    expect(Capability::DiscoversSources->isDispatchable())->toBeTrue()
        ->and(Capability::DownloadsArtifacts->isDispatchable())->toBeTrue()
        ->and(Capability::ExtractsContent->isDispatchable())->toBeFalse()
        ->and(Capability::Normalizes->isDispatchable())->toBeFalse()
        ->and(Capability::UsesAgents->isDispatchable())->toBeFalse();
});
