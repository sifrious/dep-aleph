<?php

declare(strict_types=1);

use Sifrious\Aleph\Ingestion\DomainRunQueries;
use Sifrious\Aleph\Ingestion\DomainRunScope;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\LandingDomainSyncRunAdapter;
use Sifrious\Aleph\Ingestion\LegacyRunIdentity;
use Sifrious\Aleph\Ingestion\RunStatus;

function landingDomainRunFixture(array $overrides = []): array
{
    return array_replace([
        'id' => 73,
        'provider_account_id' => 7,
        'domain_id' => null,
        'scope' => 'account',
        'label' => 'Sync cloudflare account: production',
        'status' => 'succeeded',
        'targets' => [['domain_id' => 11], ['domain_id' => 12]],
        'stats' => [
            'domains' => 2,
            'records' => 18,
            'created' => 3,
            'updated' => 4,
            'drifted' => 1,
            'removed' => 0,
        ],
        'error' => null,
        'started_at' => '2026-08-28T08:00:00+00:00',
        'finished_at' => '2026-08-28T08:02:00+00:00',
    ], $overrides);
}

it('migrates a domain run with stable package and source identities', function (): void {
    $legacy = (new LandingDomainSyncRunAdapter)->adapt(
        landingDomainRunFixture(),
        'cloudflare:account/production',
        ['dns:domain/example.com', 'dns:domain/example.net'],
        sourceInstallationId: '01K4DNSINSTALLATION00000001',
    );
    $run = app(IngestionRuns::class)->import($legacy);

    expect($run->id)->toBe(LegacyRunIdentity::for('landing:domain-sync-run/73'))
        ->and($run->sourceReference)->toBe('cloudflare:account/production')
        ->and($run->sourceInstallationId)->toBe('01K4DNSINSTALLATION00000001')
        ->and($run->connectorId)->toBe('dns');
});

it('preserves provider reconciliation ids and checkpoints inside the dns projection', function (): void {
    $legacy = (new LandingDomainSyncRunAdapter)->adapt(
        landingDomainRunFixture([
            'status' => 'running',
            'checkpoint' => ['zone_cursor' => 'cursor-2', 'record_page' => 4],
            'finished_at' => null,
        ]),
        'cloudflare:account/production',
        ['dns:domain/example.com'],
        ['cloudflare:zone/zone-1', 'cloudflare:record/record-9'],
    );
    $run = app(IngestionRuns::class)->import($legacy);
    $projection = app(DomainRunQueries::class)->find($run->id);

    expect($projection?->scope)->toBe(DomainRunScope::Account)
        ->and($projection?->domainReferences)->toBe(['dns:domain/example.com'])
        ->and($projection?->providerReconciliationIds)->toBe([
            'cloudflare:zone/zone-1',
            'cloudflare:record/record-9',
        ])
        ->and($projection?->ingestion->run->checkpoint)->toBe([
            'zone_cursor' => 'cursor-2',
            'record_page' => 4,
        ]);
});

it('uses the shared run lifecycle for retry and attempt history', function (): void {
    $run = app(IngestionRuns::class)->import((new LandingDomainSyncRunAdapter)->adapt(
        landingDomainRunFixture([
            'status' => 'failed',
            'error' => 'Provider timed out.',
            'retryable' => true,
            'checkpoint' => ['zone_cursor' => 'cursor-2'],
        ]),
        'namecheap:account/primary',
    ));

    $attempt = app(IngestionRuns::class)->beginAttempt($run);
    $projection = app(DomainRunQueries::class)->find($run->id);

    expect($attempt->number)->toBe(1)
        ->and($attempt->checkpoint)->toBe(['zone_cursor' => 'cursor-2'])
        ->and($projection?->ingestion->run->status)->toBe(RunStatus::Running)
        ->and($projection?->ingestion->attempts)->toHaveCount(1);
});

it('keeps provider sdk values outside the public contract', function (): void {
    $run = app(IngestionRuns::class)->import((new LandingDomainSyncRunAdapter)->adapt(
        landingDomainRunFixture(),
        'cloudflare:account/production',
    ));
    $serialized = serialize(app(DomainRunQueries::class)->find($run->id));

    expect($serialized)->not->toContain('App\\')
        ->and($serialized)->not->toContain('Cloudflare\\')
        ->and($serialized)->not->toContain('Namecheap\\');
});

it('retains only stable Funes references to imported domain history', function (): void {
    $run = app(IngestionRuns::class)->import((new LandingDomainSyncRunAdapter)->adapt(
        landingDomainRunFixture(),
        'cloudflare:account/production',
        acceptedReferences: [
            'funes:observation/01K4DOMAIN',
            'funes:observation/01K4DNSRECORD',
        ],
    ));
    $projection = app(DomainRunQueries::class)->find($run->id)?->toArray();

    expect($projection['run']['accepted_references'])->toBe([
        'funes:observation/01K4DOMAIN',
        'funes:observation/01K4DNSRECORD',
    ])
        ->and($projection)->not->toHaveKey('observations')
        ->and($projection)->not->toHaveKey('dns_records');
});

it('preserves every implemented Landing domain run story', function (
    string $scope,
    string $status,
    RunStatus $expected,
): void {
    $run = app(IngestionRuns::class)->import((new LandingDomainSyncRunAdapter)->adapt(
        landingDomainRunFixture([
            'id' => $scope.'-'.$status,
            'scope' => $scope,
            'status' => $status,
            'finished_at' => in_array($status, ['succeeded', 'failed'], true)
                ? '2026-08-28T08:02:00+00:00'
                : null,
        ]),
        'cloudflare:account/production',
        $scope === 'domain' ? ['dns:domain/example.com'] : [],
    ));
    $projection = app(DomainRunQueries::class)->find($run->id);

    expect($projection?->scope)->toBe(DomainRunScope::from($scope))
        ->and($projection?->ingestion->run->status)->toBe($expected);
})->with([
    'pending account dispatch' => ['account', 'pending', RunStatus::Pending],
    'running account job' => ['account', 'running', RunStatus::Running],
    'successful domain job' => ['domain', 'succeeded', RunStatus::Completed],
    'failed domain job' => ['domain', 'failed', RunStatus::Failed],
]);

it('replays migration without duplication and retains Landing persistence', function (): void {
    $legacy = (new LandingDomainSyncRunAdapter)->adapt(
        landingDomainRunFixture(),
        'cloudflare:account/production',
    );
    $runs = app(IngestionRuns::class);

    expect($runs->import($legacy)->id)->toBe($runs->import($legacy)->id)
        ->and(DB::table('aleph_ingestion_runs')->count())->toBe(1);

    $migrations = implode("\n", array_map(
        static fn (string $path): string => (string) file_get_contents($path),
        glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [],
    ));

    expect($migrations)->not->toContain("dropIfExists('domain_sync_runs')")
        ->and($migrations)->not->toContain('App\\Models\\DomainSyncRun');
});
