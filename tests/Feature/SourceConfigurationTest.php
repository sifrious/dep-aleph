<?php

declare(strict_types=1);

use Sifrious\Aleph\Connector\Capability;
use Sifrious\Aleph\Connector\Configuration\ConfigureSource;
use Sifrious\Aleph\Connector\Configuration\FunesSourceConfigurationRecorder;
use Sifrious\Aleph\Connector\Configuration\SlackWorkspaceConfigurationAdapter;
use Sifrious\Aleph\Connector\Configuration\SlackWorkspaceSourceConfigurator;
use Sifrious\Aleph\Connector\Configuration\SourceConfiguration;
use Sifrious\Aleph\Connector\Configuration\SourceConfigurationRecorder;
use Sifrious\Aleph\Connector\Configuration\SourceConfigurationRejected;
use Sifrious\Aleph\Connector\Configuration\SourceConfigurationRequest;
use Sifrious\Aleph\Connector\Configuration\WebCrawlConfigurationAdapter;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorManifest;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\CredentialKind;
use Sifrious\Aleph\Connector\UnsupportedCapability;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;
use Sifrious\Aleph\Testing\Contracts\ConnectorContract;
use Sifrious\Aleph\Testing\Fakes\ConfiguringConnector;
use Sifrious\Aleph\Testing\Fakes\MinimalConnector;

function recordingRecorder(): object
{
    return new class implements SourceConfigurationRecorder
    {
        /** @var list<SourceConfiguration> */
        public array $recorded = [];

        public function record(SourceConfiguration $configuration): void
        {
            $this->recorded[] = $configuration;
        }
    };
}

function crawlBounds(array $overrides = []): array
{
    return array_replace([
        'seeds' => ['https://www.ahsd.test/'],
        'allowed_hosts' => ['ahsd.test', '*.ahsd.test'],
        'excluded' => ['*/login*'],
        'max_pages' => 25,
        'max_depth' => 2,
    ], $overrides);
}

it('declares sources.configure as a participatory capability with one contract', function (): void {
    $connector = new ConfiguringConnector;

    expect(Capability::ConfiguresSources->value)->toBe('sources.configure')
        ->and(Capability::ConfiguresSources->isDispatchable())->toBeFalse()
        ->and(ConnectorManifest::for($connector)->capabilityIds())->toBe(['sources.configure'])
        ->and(ConnectorManifest::for($connector)->availableOperations())->toBe([])
        ->and(ConnectorContract::violations($connector))->toBe([])
        ->and(ConnectorContract::probeAll($connector))->toBe([]);
});

it('accepts a crawl bound set and returns the stable source reference', function (): void {
    $recorder = recordingRecorder();
    $connector = new ConfiguringConnector('web-crawl', $recorder);

    $configuration = $connector->configureSource(new SourceConfigurationRequest(
        sourceKey: 'ahsd',
        name: 'Abington Heights School District',
        values: crawlBounds(),
        submittedAt: new DateTimeImmutable('2026-09-02T12:00:00+00:00'),
    ));

    expect($configuration->sourceReference)->toBe('web:ahsd')
        ->and($configuration->values['seeds'])->toBe(['https://www.ahsd.test/'])
        ->and($configuration->values['limits'])->toBe(['max_pages' => 25, 'max_depth' => 2])
        ->and($configuration->credentialReference)->toBeNull()
        ->and($recorder->recorded)->toHaveCount(1)
        ->and($recorder->recorded[0]->resourceReference())->toBe('aleph:source-configuration/web:ahsd');
});

it('rejects an input the schema does not declare', function (): void {
    $connector = new ConfiguringConnector;

    expect(fn () => $connector->configureSource(new SourceConfigurationRequest(
        'ahsd',
        'Abington',
        crawlBounds(['follow_redirects' => true]),
    )))->toThrow(SourceConfigurationRejected::class, 'Input [follow_redirects] is not declared');
});

it('refuses an inline secret value and writes no credential into history', function (): void {
    $recorder = recordingRecorder();
    $configurator = new SlackWorkspaceSourceConfigurator(new MinimalConnector('slack'), $recorder);

    $rejected = null;

    try {
        $configurator->configureSource(new SourceConfigurationRequest(
            'acme',
            'Acme',
            ['workspace' => 'T0123456789', 'api_token' => 'xoxb-real-secret'],
            credentialReference: 'vault://slack/acme',
        ));
    } catch (SourceConfigurationRejected $rejection) {
        $rejected = $rejection;
    }

    expect($rejected)->not->toBeNull()
        ->and($rejected->reason)->toBe(SourceConfigurationRejected::UNKNOWN_INPUT)
        ->and($recorder->recorded)->toBe([]);
});

it('keeps a slack credential as an opaque reference and requires one', function (): void {
    $recorder = recordingRecorder();
    $configurator = new SlackWorkspaceSourceConfigurator(new MinimalConnector('slack'), $recorder);

    $configuration = $configurator->configureSource(new SourceConfigurationRequest(
        sourceKey: 'acme',
        name: 'Acme workspace',
        values: ['workspace' => 'T0123456789', 'channels' => ['C0123456789']],
        credentialReference: 'vault://slack/acme',
        submittedAt: new DateTimeImmutable('2026-09-02T12:00:00+00:00'),
    ));

    expect($configuration->sourceReference)->toBe('slack:acme')
        ->and($configuration->credentialKind)->toBe(CredentialKind::Token)
        ->and($configuration->credentialReference)->toBe('vault://slack/acme')
        ->and($configuration->values['history_days'])->toBe(30)
        ->and(json_encode($configuration->toArray()))->not->toContain('xoxb');

    expect(fn () => $configurator->configureSource(new SourceConfigurationRequest(
        'acme',
        'Acme workspace',
        ['workspace' => 'T0123456789'],
    )))->toThrow(SourceConfigurationRejected::class, 'requires a [token] credential reference');
});

it('reads an absent input from the environment before its declared default', function (): void {
    $environment = static fn (string $key): ?string => match ($key) {
        'ALEPH_WEB_MAX_PAGES' => '7',
        'ALEPH_WEB_EXCLUDED' => '*/logout*, */print*',
        default => null,
    };

    $connector = new ConfiguringConnector('web-crawl', null, $environment);

    $configuration = $connector->configureSource(new SourceConfigurationRequest(
        'ahsd',
        'Abington',
        ['seeds' => ['https://www.ahsd.test/'], 'allowed_hosts' => ['ahsd.test']],
    ));

    expect($configuration->values['limits'])->toBe(['max_pages' => 7, 'max_depth' => 2])
        ->and($configuration->values['excluded'])->toBe(['*/logout*', '*/print*'])
        ->and($configuration->values['query_parameters'])->toBe([]);
});

it('rejects a required input that no submission, environment, or default supplies', function (): void {
    $connector = new ConfiguringConnector;

    expect(fn () => $connector->configureSource(new SourceConfigurationRequest(
        'ahsd',
        'Abington',
        ['seeds' => ['https://www.ahsd.test/']],
    )))->toThrow(SourceConfigurationRejected::class, 'Input [allowed_hosts] is required');
});

it('rejects values that fall outside the source bounds', function (): void {
    $configurator = new SlackWorkspaceSourceConfigurator(new MinimalConnector('slack'));

    expect(fn () => $configurator->configureSource(new SourceConfigurationRequest(
        'acme',
        'Acme',
        ['workspace' => 'acme-corp'],
        credentialReference: 'vault://slack/acme',
    )))->toThrow(SourceConfigurationRejected::class, 'must be a workspace identifier');
});

it('persists an accepted configuration and hands later runs the source reference', function (): void {
    $connector = new ConfiguringConnector('web-crawl');
    app(ConnectorRegistry::class)->register($connector);

    $configured = app(ConfigureSource::class)->configure('web-crawl', new SourceConfigurationRequest(
        'ahsd',
        'Abington Heights School District',
        crawlBounds(),
    ));

    $stored = app(ConnectorInstallations::class)->find($configured->installation->id);

    expect($configured->sourceReference())->toBe('web:ahsd')
        ->and($stored)->not->toBeNull()
        ->and($stored->externalAccountId)->toBe('web:ahsd')
        ->and($stored->configuration['limits'])->toBe(['max_pages' => 25, 'max_depth' => 2])
        ->and($stored->credentialsReference)->toBeNull();
});

it('refuses to configure a source through a connector that does not configure sources', function (): void {
    app(ConnectorRegistry::class)->register(new MinimalConnector('bare'));

    expect(fn () => app(ConfigureSource::class)->configure('bare', new SourceConfigurationRequest('ahsd', 'Abington')))
        ->toThrow(UnsupportedCapability::class, 'does not configure sources');
});

it('records the declaration as an observation Funes accepts', function (): void {
    $recorder = new FunesSourceConfigurationRecorder(app(EnvelopeSubmitter::class));
    $connector = new ConfiguringConnector('web-crawl', $recorder);

    $configuration = $connector->configureSource(new SourceConfigurationRequest(
        sourceKey: 'ahsd',
        name: 'Abington Heights School District',
        values: crawlBounds(),
        submittedAt: new DateTimeImmutable('2026-09-02T12:00:00+00:00'),
    ));

    $draft = app(EnvelopeSubmitter::class)->draft(new ObservationEnvelope(
        sourceReference: $configuration->sourceReference,
        sourceName: $configuration->name,
        resourceReference: $configuration->resourceReference(),
        observedAt: $configuration->configuredAt,
        payload: (string) json_encode($configuration->toArray()),
        provenance: new Provenance(
            $configuration->connectorId,
            $configuration->connectorVersion,
            $configuration->sourceReference,
            $configuration->configuredAt,
        ),
        contentType: 'application/json',
    ));

    expect($draft->sourceReference)->toBe('web:ahsd')
        ->and($draft->resourceReference)->toBe('aleph:source-configuration/web:ahsd');
});

it('documents every declared input on the capability page', function (): void {
    $page = (string) file_get_contents(dirname(__DIR__, 2).'/docs/capabilities/sources-configure.md');

    foreach ([new WebCrawlConfigurationAdapter, new SlackWorkspaceConfigurationAdapter] as $adapter) {
        foreach ($adapter->schema()->fields as $field) {
            expect($page)->toContain('`'.$field->name.'`');

            if ($field->envKey !== null) {
                expect($page)->toContain($field->envKey);
            }
        }
    }
});
