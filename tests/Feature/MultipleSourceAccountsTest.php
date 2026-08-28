<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Connector\Capability as ConnectorCapability;
use Sifrious\Aleph\Connector\ConnectorCredentials;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\CredentialInput;
use Sifrious\Aleph\Connector\CredentialKind;
use Sifrious\Aleph\Connector\Health\ConnectorHealthChecks;
use Sifrious\Aleph\Connector\Health\HealthCheck;
use Sifrious\Aleph\Connector\Health\HealthStatus;
use Sifrious\Aleph\Connector\RegisterSourceAccount;
use Sifrious\Aleph\Connector\SourceAccountRegistration;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;
use Sifrious\Aleph\Ingestion\Capability;
use Sifrious\Aleph\Ingestion\CheckpointRule;
use Sifrious\Aleph\Ingestion\CheckpointValue;
use Sifrious\Aleph\Ingestion\IngestionCheckpoints;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\IngestionSchedules;
use Sifrious\Aleph\Ingestion\SourceStream;
use Sifrious\Aleph\Ingestion\SourceStreams;
use Sifrious\Aleph\Testing\Fakes\DiscoveryAndDownloadConnector;

function accountIsolationFixture(): array
{
    $connector = new DiscoveryAndDownloadConnector;
    app(ConnectorRegistry::class)->register($connector);
    $register = app(RegisterSourceAccount::class);
    $first = $register->register($connector, new SourceAccountRegistration(
        label: 'North archive',
        externalAccountId: 'tenant:north',
        funesSourceAccountId: 'source-account:north',
        settings: ['region' => 'north'],
        owner: 'user:1',
        credential: new CredentialInput(
            CredentialKind::OAuth2,
            ['access_token' => 'north-access', 'refresh_token' => 'north-refresh'],
            ['files.read'],
            ['token_endpoint' => 'https://provider.test/oauth/token'],
            new DateTimeImmutable('2026-08-28T16:00:00+00:00'),
        ),
    ));
    $second = $register->register($connector, new SourceAccountRegistration(
        label: 'South archive',
        externalAccountId: 'tenant:south',
        funesSourceAccountId: 'source-account:south',
        settings: ['region' => 'south'],
        owner: 'user:1',
        credential: new CredentialInput(
            CredentialKind::OAuth2,
            ['access_token' => 'south-access', 'refresh_token' => 'south-refresh'],
            ['files.read', 'files.metadata'],
            ['token_endpoint' => 'https://provider.test/oauth/token'],
            new DateTimeImmutable('2026-08-28T17:00:00+00:00'),
        ),
    ));

    return [$connector, $first, $second];
}

function acceptAccountObservation(string $installationId, string $runId, string $resource): string
{
    $record = app(EnvelopeSubmitter::class)->submit(new ObservationEnvelope(
        sourceReference: 'account:'.$installationId,
        sourceName: 'Account '.$installationId,
        resourceReference: $resource,
        observedAt: new DateTimeImmutable('2026-08-28T15:00:00+00:00'),
        payload: $resource,
        provenance: new Provenance(
            'archive-drop',
            '1.0.0',
            $installationId,
            new DateTimeImmutable('2026-08-28T15:00:00+00:00'),
            $runId,
        ),
    ));

    return (string) $record->acceptedId();
}

function checkpointAccount(SourceStream $stream, int $position): void
{
    $runs = app(IngestionRuns::class);
    $run = $runs->start(
        'account:'.$stream->sourceInstallationId,
        Capability::IncrementalSync,
        [],
        connectorId: 'archive-drop',
        sourceInstallationId: $stream->sourceInstallationId,
    );
    $attempt = $runs->beginAttempt($run);
    $accepted = acceptAccountObservation($stream->sourceInstallationId, $run->id, 'item:'.$position);
    $runs->recordProgress($run, $attempt, [], ['accepted' => 1], [$accepted]);
    app(IngestionCheckpoints::class)->commit(
        $stream,
        Capability::IncrementalSync,
        'account',
        new CheckpointValue('provider.cursor', '1', 'cursor-'.$position, CheckpointRule::Monotonic, $position),
        0,
        $run,
        [$accepted],
        $attempt,
    );
}

it('registers and refreshes two encrypted account credentials without crossing references', function (): void {
    [, $first, $second] = accountIsolationFixture();
    $credentials = app(ConnectorCredentials::class);
    $firstReference = (string) $first->installation->credentialsReference;
    $secondReference = (string) $second->installation->credentialsReference;
    $stored = DB::table('aleph_connector_credentials')->orderBy('source_installation_id')->get();
    $refreshed = $credentials->refresh(
        $firstReference,
        new CredentialInput(
            CredentialKind::OAuth2,
            ['access_token' => 'north-access-2', 'refresh_token' => 'north-refresh-2'],
            ['files.read', 'files.write'],
            ['refresh_count' => 1],
            new DateTimeImmutable('2026-08-28T18:00:00+00:00'),
        ),
        new DateTimeImmutable('2026-08-28T15:30:00+00:00'),
    );

    expect($first->installation->externalAccountId)->toBe('tenant:north')
        ->and($second->installation->externalAccountId)->toBe('tenant:south')
        ->and($first->installation->funesSourceAccountId)->toBe('source-account:north')
        ->and($firstReference)->not->toBe($secondReference)
        ->and($stored)->toHaveCount(2)
        ->and((string) $stored[0]->material)->not->toContain('north-access')
        ->and((string) $stored[1]->material)->not->toContain('south-access')
        ->and($refreshed->material['access_token'])->toBe('north-access-2')
        ->and($refreshed->scopes)->toBe(['files.read', 'files.write'])
        ->and($refreshed->refreshMetadata)->toBe(['refresh_count' => 1])
        ->and($credentials->find($secondReference)?->material['access_token'])->toBe('south-access')
        ->and($credentials->find($secondReference)?->scopes)->toBe(['files.read', 'files.metadata'])
        ->and($credentials->find($secondReference)?->refreshedAt)->toBeNull()
        ->and($first->credential?->metadata())->not->toHaveKey('material');
});

it('isolates checkpoints health and schedules for two accounts of one connector', function (): void {
    [, $first, $second] = accountIsolationFixture();
    $streams = app(SourceStreams::class);
    $firstStream = $streams->create($first->installation->id, 'root', 'archive', 'north');
    $secondStream = $streams->create($second->installation->id, 'root', 'archive', 'south');
    checkpointAccount($firstStream, 10);
    checkpointAccount($secondStream, 90);
    $checks = app(ConnectorHealthChecks::class);
    $checkedAt = new DateTimeImmutable('2026-08-28T15:00:00+00:00');
    $expiresAt = new DateTimeImmutable('2026-08-28T16:00:00+00:00');
    $checks->record($first->installation->id, HealthCheck::Authentication, HealthStatus::Healthy, 'North credentials accepted.', [], null, $checkedAt, $expiresAt);
    $checks->record($second->installation->id, HealthCheck::Authentication, HealthStatus::Unhealthy, 'South credentials rejected.', [], null, $checkedAt, $expiresAt);
    $schedules = app(IngestionSchedules::class);
    $firstSchedule = $schedules->create($first->installation->id, ConnectorCapability::DiscoversSources, '*/10 * * * *', 'UTC', ['limit' => 10], $checkedAt);
    $secondSchedule = $schedules->create($second->installation->id, ConnectorCapability::DiscoversSources, '0 * * * *', 'UTC', ['limit' => 90], $checkedAt);
    $checkpoints = app(IngestionCheckpoints::class);

    expect($checkpoints->latest($firstStream, Capability::IncrementalSync, 'account')?->value->value)->toBe('cursor-10')
        ->and($checkpoints->latest($secondStream, Capability::IncrementalSync, 'account')?->value->value)->toBe('cursor-90')
        ->and($checks->latest($first->installation->id, HealthCheck::Authentication)?->status)->toBe(HealthStatus::Healthy)
        ->and($checks->latest($second->installation->id, HealthCheck::Authentication)?->status)->toBe(HealthStatus::Unhealthy)
        ->and($schedules->forInstallation($first->installation->id))->toHaveCount(1)
        ->and($schedules->forInstallation($first->installation->id)[0]->id)->toBe($firstSchedule->id)
        ->and($schedules->forInstallation($second->installation->id))->toHaveCount(1)
        ->and($schedules->forInstallation($second->installation->id)[0]->id)->toBe($secondSchedule->id)
        ->and($firstSchedule->constraints)->toBe(['limit' => 10])
        ->and($secondSchedule->constraints)->toBe(['limit' => 90]);
});
