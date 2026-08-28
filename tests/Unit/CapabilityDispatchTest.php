<?php

declare(strict_types=1);

use Sifrious\Aleph\Connector\Capability;
use Sifrious\Aleph\Connector\ConnectorDispatcher;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\Rejection;
use Sifrious\Aleph\Connector\UnsupportedCapability;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;
use Sifrious\Aleph\Connector\Values\DiscoveredSources;
use Sifrious\Aleph\Connector\Values\OperationRequest;
use Sifrious\Aleph\Testing\Fakes\CompositeConnector;
use Sifrious\Aleph\Testing\Fakes\DiscoveryConnector;
use Sifrious\Aleph\Testing\Fakes\IncrementalConnector;
use Sifrious\Aleph\Testing\Fakes\MinimalConnector;

function dispatcherFor(object ...$connectors): ConnectorDispatcher
{
    $registry = new ConnectorRegistry;

    foreach ($connectors as $connector) {
        $registry->register($connector);
    }

    return new ConnectorDispatcher($registry);
}

it('dispatches a supported operation to the connector', function (): void {
    $connector = new DiscoveryConnector('archive');
    $result = dispatcherFor($connector)->dispatch(
        'archive',
        Capability::DiscoversSources,
        new OperationRequest('archive:root'),
    );

    expect($result)->toBeInstanceOf(DiscoveredSources::class)
        ->and($result->references())->toBe(['archive:root/alpha', 'archive:root/beta'])
        ->and($connector->discoveryCalls)->toHaveCount(1);
});

it('rejects an unsupported operation before the connector is touched', function (): void {
    $connector = new DiscoveryConnector('archive');
    $dispatcher = dispatcherFor($connector);

    expect($dispatcher->supports('archive', Capability::Backfills))->toBeFalse();

    $rejection = $dispatcher->rejectionFor('archive', Capability::Backfills);

    expect($rejection)->not->toBeNull()
        ->and($rejection->reason)->toBe(Rejection::CAPABILITY_NOT_SUPPORTED)
        ->and($rejection->supported)->toBe(['sources.discover'])
        ->and($connector->discoveryCalls)->toBe([]);
});

it('throws a machine readable failure when dispatching an unsupported operation', function (): void {
    $dispatcher = dispatcherFor(new DiscoveryConnector('archive'));

    try {
        $dispatcher->dispatch('archive', Capability::ConsumesWebhooks, new OperationRequest('archive:root'));
        $this->fail('dispatch should have refused an unsupported capability');
    } catch (UnsupportedCapability $failure) {
        expect($failure->rejection->toArray())->toMatchArray([
            'reason' => Rejection::CAPABILITY_NOT_SUPPORTED,
            'connector' => 'archive',
            'capability' => 'webhooks.consume',
            'supported' => ['sources.discover'],
        ]);
    }
});

it('rejects an unknown connector without guessing', function (): void {
    $rejection = dispatcherFor()->rejectionFor('nope', Capability::DiscoversSources);

    expect($rejection->reason)->toBe(Rejection::UNKNOWN_CONNECTOR)
        ->and($rejection->supported)->toBe([]);
});

it('refuses to dispatch a participatory capability as a standalone operation', function (): void {
    $rejection = dispatcherFor(new CompositeConnector('many'))
        ->rejectionFor('many', Capability::Normalizes);

    expect($rejection->reason)->toBe(Rejection::CAPABILITY_NOT_DISPATCHABLE);
});

it('rejects every capability for a connector that implements none', function (): void {
    $dispatcher = dispatcherFor(new MinimalConnector('bare'));

    foreach (Capability::dispatchable() as $capability) {
        expect($dispatcher->supports('bare', $capability))->toBeFalse();
    }
});

it('passes a cursor through to an incremental connector', function (): void {
    $connector = new IncrementalConnector('feed');
    $dispatcher = dispatcherFor($connector);

    $first = $dispatcher->dispatch('feed', Capability::SyncsIncrementally, new OperationRequest('feed:main'));
    expect($first->complete)->toBeFalse()->and($first->cursor)->toBe('cursor-1');

    $second = $dispatcher->dispatch(
        'feed',
        Capability::SyncsIncrementally,
        new OperationRequest('feed:main', cursor: $first->cursor),
    );

    expect($second->complete)->toBeTrue()
        ->and($second->metadata['resumed_from'])->toBe('cursor-1');
});

it('refuses a request of the wrong shape', function (): void {
    dispatcherFor(new DiscoveryConnector('archive'))
        ->dispatch('archive', Capability::DiscoversSources, new ArtifactRequest('a', 'b'));
})->throws(InvalidArgumentException::class);

it('finds every connector supporting a capability without provider knowledge', function (): void {
    $registry = new ConnectorRegistry;
    $registry->register(new DiscoveryConnector('one'));
    $registry->register(new IncrementalConnector('two'));
    $registry->register(new MinimalConnector('three'));

    expect($registry->supporting(Capability::DiscoversSources))->toHaveCount(1)
        ->and($registry->supporting(Capability::SyncsIncrementally))->toHaveCount(1)
        ->and($registry->supporting(Capability::UsesAgents))->toHaveCount(0);
});
