<?php

declare(strict_types=1);

use Sifrious\Aleph\Connector\Capability;
use Sifrious\Aleph\Connector\ConnectorCatalogue;
use Sifrious\Aleph\Connector\ConnectorDispatcher;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ExtensionMetadata;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\ObservationMetadata;
use Sifrious\Aleph\Envelope\Provenance;
use Sifrious\Aleph\Testing\Fakes\DiscoveryAndDownloadConnector;
use Sifrious\Aleph\Testing\Fakes\MinimalConnector;
use Sifrious\Funes\Persistence\ObservationStore;
use Sifrious\Funes\Value\Observation;

function submitEnvelope(array $extensions = []): Observation
{
    $envelope = new ObservationEnvelope(
        sourceReference: 'weird:stream/one',
        sourceName: 'Weird Stream',
        resourceReference: 'weird:item/'.bin2hex(random_bytes(4)),
        observedAt: new DateTimeImmutable('2026-08-27T09:00:00+00:00'),
        payload: 'body',
        provenance: new Provenance('weird', '1.0.0', 'inst-1', new DateTimeImmutable('2026-08-27T09:05:00+00:00')),
        extensions: $extensions,
    );

    app(EnvelopeSubmitter::class)->submit($envelope);

    $id = DB::table('funes_observations')
        ->join('funes_resources', 'funes_resources.id', '=', 'funes_observations.resource_id')
        ->where('funes_resources.canonical_reference', $envelope->resourceReference)
        ->value('funes_observations.id');

    return app(ObservationStore::class)->get((string) $id) ?? throw new RuntimeException('Accepted observation was not readable.');
}

it('submits a generic envelope into Funes universal tables', function (): void {
    $observation = submitEnvelope();

    expect(ObservationMetadata::aleph($observation)['envelope_version'])->toBe(1)
        ->and(DB::table('funes_observations')->count())->toBe(1);
});

it('preserves an extension namespace and version through acceptance', function (): void {
    $observation = submitEnvelope([new ExtensionMetadata('weirdservice.widget', 7, ['ring' => 'RN-9'])]);

    $metadata = $observation->metadata('aleph:extension/weirdservice.widget')[0];

    expect(ObservationMetadata::extension($observation, 'weirdservice.widget'))->toBe(['ring' => 'RN-9'])
        ->and($metadata->schemaVersion)->toBe('7');
});

it('preserves extension data Funes has never heard of', function (): void {
    $observation = submitEnvelope([new ExtensionMetadata('entirely.unknown', 1, [
        'nested' => ['deeply' => ['odd' => true]],
        'list' => [1, 2, 3],
    ])]);

    $metadata = ObservationMetadata::extension($observation, 'entirely.unknown');

    expect($metadata['nested']['deeply']['odd'])->toBeTrue()
        ->and($metadata['list'])->toBe([1, 2, 3]);
});

it('has no provider column anywhere in the Funes schema', function (): void {
    $columns = [];

    foreach (['funes_sources', 'funes_resources', 'funes_observations', 'funes_payloads'] as $table) {
        $columns = [...$columns, ...Schema::getColumnListing($table)];
    }

    foreach (['provider', 'connector', 'slack', 'github', 'pigeon'] as $forbidden) {
        expect(implode(',', $columns))->not->toContain($forbidden);
    }
});

it('projects a catalogue from registered packages', function (): void {
    app(ConnectorRegistry::class)->register(new DiscoveryAndDownloadConnector);

    $entry = app(ConnectorCatalogue::class)->find('archive-drop');

    expect($entry->id)->toBe('archive-drop')
        ->and($entry->version)->toBe('2.1.0')
        ->and($entry->enabled)->toBeTrue()
        ->and($entry->package)->toBe('sifrious/aleph')
        ->and($entry->manifest->capabilityIds())->toBe(['sources.discover', 'artifacts.download']);
});

it('honours a disabled connector without unregistering it', function (): void {
    config()->set('aleph.connectors.disabled', ['archive-drop']);
    app()->forgetInstance(ConnectorCatalogue::class);
    app(ConnectorRegistry::class)->register(new DiscoveryAndDownloadConnector);

    $catalogue = app(ConnectorCatalogue::class);

    expect($catalogue->isEnabled('archive-drop'))->toBeFalse()
        ->and($catalogue->find('archive-drop')->enabled)->toBeFalse()
        ->and($catalogue->enabled())->toBe([]);
});

it('replaces a connector registered twice under one id', function (): void {
    $registry = app(ConnectorRegistry::class);
    $registry->register(new DiscoveryAndDownloadConnector);
    $registry->register(new DiscoveryAndDownloadConnector);

    expect($registry->ids())->toBe(['archive-drop'])
        ->and(app(ConnectorCatalogue::class)->entries())->toHaveCount(1);
});

it('supports several installations of one connector', function (): void {
    $registry = app(ConnectorRegistry::class);
    $registry->register(new DiscoveryAndDownloadConnector);
    $installations = app(ConnectorInstallations::class);
    $connector = $registry->get('archive-drop');

    $installations->create($connector, 'North', ['base_url' => 'https://north.example']);
    $installations->create($connector, 'South', ['base_url' => 'https://south.example']);

    $found = $installations->forConnector('archive-drop');

    expect($found)->toHaveCount(2)
        ->and(array_column(array_map(fn ($i) => $i->toArray(), $found), 'label'))->toBe(['North', 'South'])
        ->and($found[0]->configuration['base_url'])->toBe('https://north.example');
});

it('stores installation configuration encrypted at rest', function (): void {
    $registry = app(ConnectorRegistry::class);
    $registry->register(new DiscoveryAndDownloadConnector);

    app(ConnectorInstallations::class)->create(
        $registry->get('archive-drop'),
        'North',
        ['base_url' => 'https://north.example'],
        credentialsReference: 'vault://archive/north',
    );

    $stored = DB::table('aleph_connector_installations')->value('configuration');

    expect($stored)->not->toContain('north.example')
        ->and(DB::table('aleph_connector_installations')->value('credentials_reference'))
        ->toBe('vault://archive/north');
});

it('enables and disables an installation independently of the connector', function (): void {
    $registry = app(ConnectorRegistry::class);
    $registry->register(new DiscoveryAndDownloadConnector);
    $installations = app(ConnectorInstallations::class);

    $installation = $installations->create($registry->get('archive-drop'), 'North');
    $installations->disable($installation->id);

    expect($installations->find($installation->id)->enabled)->toBeFalse()
        ->and($installations->enabled())->toBe([]);

    $installations->enable($installation->id);
    expect($installations->find($installation->id)->enabled)->toBeTrue();
});

it('requires no provider knowledge in generic dispatch', function (): void {
    $registry = app(ConnectorRegistry::class);
    $registry->register(new DiscoveryAndDownloadConnector);
    $registry->register(new MinimalConnector('bare'));

    $dispatcher = app(ConnectorDispatcher::class);

    expect($dispatcher->supports('archive-drop', Capability::DiscoversSources))->toBeTrue()
        ->and($dispatcher->supports('bare', Capability::DiscoversSources))->toBeFalse();

    $source = (string) file_get_contents(
        (new ReflectionClass(ConnectorDispatcher::class))->getFileName()
    );

    foreach (['archive-drop', 'pigeon', 'slack', 'github', 'switch (', 'provider'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }
});
