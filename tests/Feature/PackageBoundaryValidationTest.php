<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Connector\Capability;
use Sifrious\Aleph\Connector\ConnectorDispatcher;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\Health\ConnectorHealthChecks;
use Sifrious\Aleph\Connector\Health\HealthCheck;
use Sifrious\Aleph\Connector\Health\HealthStatus;
use Sifrious\Aleph\Connector\Values\DiscoveredSources;
use Sifrious\Aleph\Connector\Values\OperationRequest;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\ObservationMetadata;
use Sifrious\Aleph\Envelope\Provenance;
use Sifrious\Aleph\Testing\Fakes\DiscoveryAndDownloadConnector;
use Sifrious\Funes\Persistence\ObservationStore;

it('keeps provider discovery out of Funes and provider SDKs out of the Aleph core', function (): void {
    $funesSource = '';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/vendor/sifrious/funes/src'));

    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $funesSource .= file_get_contents($file->getPathname());
        }
    }

    $composer = (string) file_get_contents(dirname(__DIR__, 2).'/composer.json');

    expect($funesSource)->not->toContain('Sifrious\Aleph')
        ->and($funesSource)->not->toContain('DiscoversSources')
        ->and($composer)->not->toMatch('/(github|slack|linear|cloudflare|namecheap).*sdk/i');
});

it('publishes collection capabilities without a generic provider mutation capability', function (): void {
    $capabilities = array_map(static fn (Capability $capability): string => $capability->value, Capability::cases());

    expect($capabilities)->not->toContain('records.write')
        ->and($capabilities)->not->toContain('resources.update')
        ->and($capabilities)->not->toContain('resources.delete')
        ->and($capabilities)->not->toContain('provider.mutate');
});

it('delivers connector discovery through Funes with provenance and deterministic replay', function (): void {
    $connector = new DiscoveryAndDownloadConnector;
    $registry = app(ConnectorRegistry::class);
    $registry->register($connector);
    $installation = app(ConnectorInstallations::class)->create(
        $connector,
        'Boundary validation',
        credentialsReference: 'vault://boundary/archive',
        externalAccountId: 'archive-account',
        funesSourceAccountId: 'source-account:archive',
    );
    $discovered = app(ConnectorDispatcher::class)->dispatch(
        $connector->id(),
        Capability::DiscoversSources,
        new OperationRequest('archive-drop:root'),
    );

    expect($discovered)->toBeInstanceOf(DiscoveredSources::class);

    $source = $discovered->sources[0];
    $envelope = new ObservationEnvelope(
        sourceReference: 'archive-drop:root',
        sourceName: 'Archive Drop',
        resourceReference: $source->reference,
        observedAt: new DateTimeImmutable('2026-08-28T15:00:00+00:00'),
        payload: json_encode($source->toArray(), JSON_THROW_ON_ERROR),
        provenance: new Provenance(
            $connector->id(),
            $connector->version(),
            $installation->id,
            new DateTimeImmutable('2026-08-28T15:00:00+00:00'),
            'run:boundary',
        ),
        account: $installation->externalAccountId,
        stream: 'archive-years',
        eventType: 'source.discovered',
        providerId: $source->reference,
    );
    $submitter = app(EnvelopeSubmitter::class);
    $first = $submitter->submit($envelope, 'attempt:boundary-1');
    $replay = $submitter->submit($envelope, 'attempt:boundary-2');
    $acceptedId = (string) $first->acceptedId();
    $metadata = ObservationMetadata::aleph(app(ObservationStore::class)->get($acceptedId));

    expect($replay->acceptedId())->toBe($acceptedId)
        ->and(DB::table('funes_observations')->count())->toBe(1)
        ->and($metadata['account'])->toBe('archive-account')
        ->and($metadata['stream'])->toBe('archive-years')
        ->and($metadata['event_type'])->toBe('source.discovered')
        ->and($metadata['provider_id'])->toBe($source->reference)
        ->and($metadata['provenance']['connector'])->toBe('archive-drop')
        ->and($metadata['provenance']['installation'])->toBe($installation->id)
        ->and($metadata['provenance']['run'])->toBe('run:boundary');
});

it('observes account health without changing accepted history', function (): void {
    $connector = new DiscoveryAndDownloadConnector;
    app(ConnectorRegistry::class)->register($connector);
    $installation = app(ConnectorInstallations::class)->create($connector, 'Unhealthy boundary source');
    $checks = app(ConnectorHealthChecks::class);
    $checks->record(
        $installation->id,
        HealthCheck::Authentication,
        HealthStatus::Unhealthy,
        'The provider rejected the stored credential.',
        [],
        null,
        new DateTimeImmutable('2026-08-28T15:00:00+00:00'),
        new DateTimeImmutable('2026-08-28T15:05:00+00:00'),
    );

    expect($checks->latest($installation->id, HealthCheck::Authentication)?->status)->toBe(HealthStatus::Unhealthy)
        ->and($checks->latest($installation->id, HealthCheck::Authentication)?->message)->toContain('rejected')
        ->and(DB::table('funes_observations')->count())->toBe(0);
});
