<?php

declare(strict_types=1);

use Sifrious\Aleph\Connector\Capability;
use Sifrious\Aleph\Connector\ConfigurationField;
use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\Connector;
use Sifrious\Aleph\Connector\Contracts\DiscoversSources;
use Sifrious\Aleph\Connector\Values\DiscoveredSources;
use Sifrious\Aleph\Connector\Values\OperationRequest;
use Sifrious\Aleph\Testing\Contracts\ConnectorContract;
use Sifrious\Aleph\Testing\Fakes\BaseFakeConnector;
use Sifrious\Aleph\Testing\Fakes\CompositeConnector;
use Sifrious\Aleph\Testing\Fakes\DiscoveryConnector;
use Sifrious\Aleph\Testing\Fakes\DownloadConnector;
use Sifrious\Aleph\Testing\Fakes\HealthyConnector;
use Sifrious\Aleph\Testing\Fakes\IncrementalConnector;
use Sifrious\Aleph\Testing\Fakes\MinimalConnector;
use Sifrious\Aleph\Testing\Fakes\WebhookConnector;

it('passes every shipped fake', function (): void {
    $connectors = [
        new MinimalConnector('bare'),
        new DiscoveryConnector('one'),
        new CompositeConnector('many'),
        new DownloadConnector('dl'),
        new IncrementalConnector('inc'),
        new WebhookConnector('hook'),
        new HealthyConnector('hc'),
    ];

    foreach ($connectors as $connector) {
        expect(ConnectorContract::violations($connector))->toBe([])
            ->and(ConnectorContract::probeAll($connector))->toBe([]);
    }
});

it('rejects an empty or malformed connector id', function (): void {
    $violations = ConnectorContract::identityViolations(new MinimalConnector('Not A Slug'));

    expect($violations)->toContain('connector id [Not A Slug] must be a lowercase slug');
});

it('flags a credential-shaped field that is not marked secret', function (): void {
    $connector = new class extends BaseFakeConnector
    {
        public function __construct()
        {
            parent::__construct('leaky');
        }

        public function configuration(): ConfigurationSchema
        {
            return new ConfigurationSchema(ConfigurationField::text('api_token', 'oops'));
        }
    };

    expect(ConnectorContract::configurationViolations($connector))
        ->toContain('configuration field [api_token] looks like a credential but is not marked secret');
});

it('catches a connector whose capability method returns the wrong type', function (): void {
    $connector = new class implements Connector, DiscoversSources
    {
        public function id(): string
        {
            return 'wrong-return';
        }

        public function name(): string
        {
            return 'Wrong return';
        }

        public function version(): string
        {
            return '1.0.0';
        }

        public function configuration(): ConfigurationSchema
        {
            return ConfigurationSchema::none();
        }

        public function discoverSources(OperationRequest $request): DiscoveredSources
        {
            throw new RuntimeException('provider exploded');
        }
    };

    expect(ConnectorContract::probe($connector, Capability::DiscoversSources))
        ->toHaveCount(1)
        ->and(ConnectorContract::probe($connector, Capability::DiscoversSources)[0])
        ->toContain('provider exploded');
});

it('reports a violation when probing a capability the connector lacks', function (): void {
    expect(ConnectorContract::probe(new MinimalConnector('bare'), Capability::Backfills))
        ->toBe(['connector does not implement '.Capability::Backfills->contract()]);
});

it('cannot let the manifest drift from the implemented interfaces', function (): void {
    foreach ([new MinimalConnector('bare'), new DiscoveryConnector('one'), new CompositeConnector('many')] as $connector) {
        expect(ConnectorContract::manifestViolations($connector))->toBe([]);
    }
});
