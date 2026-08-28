<?php

declare(strict_types=1);

use Sifrious\Aleph\Connector\ConfigurationField;
use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\ConnectorManifest;
use Sifrious\Aleph\Testing\Fakes\DiscoveryAndDownloadConnector;
use Sifrious\Aleph\Testing\Fakes\MinimalConnector;

it('exposes identity, capabilities and configuration without behaviour', function (): void {
    $manifest = ConnectorManifest::for(new DiscoveryAndDownloadConnector);

    expect($manifest->id)->toBe('archive-drop')
        ->and($manifest->name)->toBe('Archive Drop')
        ->and($manifest->version)->toBe('2.1.0')
        ->and($manifest->capabilityIds())->toBe(['sources.discover', 'artifacts.download']);
});

it('describes configuration without carrying credential values', function (): void {
    $manifest = ConnectorManifest::for(new DiscoveryAndDownloadConnector);
    $encoded = json_encode($manifest->toArray(), JSON_THROW_ON_ERROR);

    expect($manifest->configuration->secrets())->toBe(['api_token'])
        ->and($encoded)->not->toContain('value');

    foreach ($manifest->configuration->toArray() as $field) {
        expect($field)->toHaveKeys(['name', 'type', 'required', 'secret', 'description'])
            ->and($field)->not->toHaveKey('value');
    }
});

it('reports no operations for a connector with no capabilities', function (): void {
    $manifest = ConnectorManifest::for(new MinimalConnector('bare'));

    expect($manifest->capabilityIds())->toBe([])
        ->and($manifest->availableOperations())->toBe([]);
});

it('treats a schema with no fields as valid', function (): void {
    expect(ConfigurationSchema::none())->toHaveCount(0)
        ->and(ConfigurationSchema::none()->required())->toBe([]);
});

it('marks required and secret fields distinctly', function (): void {
    $schema = new ConfigurationSchema(
        ConfigurationField::text('host'),
        ConfigurationField::secret('token'),
        ConfigurationField::boolean('strict'),
    );

    expect($schema->required())->toBe(['host', 'token'])
        ->and($schema->secrets())->toBe(['token']);
});
