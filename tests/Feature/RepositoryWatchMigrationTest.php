<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\Watch\LandingRepoWatchAdapter;
use Sifrious\Aleph\Connector\Watch\LegacyRepositoryWatch;
use Sifrious\Aleph\Connector\Watch\RepositoryWatches;
use Sifrious\Aleph\Connector\Watch\RepositoryWatchIdentity;
use Sifrious\Aleph\Connector\Watch\RepositoryWatchMode;
use Sifrious\Aleph\Connector\Watch\RepositoryWatchStatus;
use Sifrious\Aleph\Testing\Fakes\MinimalConnector;

function landingRepoWatchFixture(array $overrides = []): array
{
    return array_replace([
        'id' => 12,
        'user_id' => 7,
        'repository_id' => 8,
        'cadence_minutes' => 5,
        'last_synced_at' => '2026-08-28T10:00:00+00:00',
        'backfill_completed_at' => '2026-08-28T09:00:00+00:00',
        'next_sync_at' => '2026-08-28T10:05:00+00:00',
        'last_indexed_ref' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'enabled' => true,
        'last_error' => null,
        'created_at' => '2026-08-01T10:00:00+00:00',
        'updated_at' => '2026-08-28T10:00:00+00:00',
    ], $overrides);
}

function repositoryWatchInstallation(string $account = 'github:account/acme'): object
{
    return app(ConnectorInstallations::class)->create(
        new MinimalConnector('watch-fixture'),
        $account,
        externalAccountId: $account,
        funesSourceAccountId: 'source-'.$account,
    );
}

function adaptRepositoryWatch(object $installation, array $overrides = []): LegacyRepositoryWatch
{
    return (new LandingRepoWatchAdapter)->adapt(
        landingRepoWatchFixture($overrides),
        $installation->id,
        'github:account/acme',
        'git:repository/acme/widget',
        RepositoryWatchMode::Hybrid,
        ['pull_request_state' => 'all', 'branch' => 'main'],
        ['graphql_cursor' => 'cursor-3'],
    );
}

it('migrates a Landing watch with stable portable identity and complete source-backed state', function (): void {
    $installation = repositoryWatchInstallation();
    $watch = app(RepositoryWatches::class)->import(adaptRepositoryWatch($installation));

    expect($watch->id)->toBe(RepositoryWatchIdentity::for('landing:repo-watch/12'))
        ->and($watch->legacyReference)->toBe('landing:repo-watch/12')
        ->and($watch->sourceInstallationId)->toBe($installation->id)
        ->and($watch->sourceReference)->toBe('github:account/acme')
        ->and($watch->repositoryReference)->toBe('git:repository/acme/widget')
        ->and($watch->mode)->toBe(RepositoryWatchMode::Hybrid)
        ->and($watch->filters)->toBe(['pull_request_state' => 'all', 'branch' => 'main'])
        ->and($watch->checkpoint)->toBe(['graphql_cursor' => 'cursor-3'])
        ->and($watch->cadenceSeconds)->toBe(300)
        ->and($watch->headReference)->toBe('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
        ->and($watch->enabled)->toBeTrue()
        ->and($watch->lastSyncedAt?->format(DATE_ATOM))->toBe('2026-08-28T10:00:00+00:00')
        ->and($watch->backfillCompletedAt?->format(DATE_ATOM))->toBe('2026-08-28T09:00:00+00:00')
        ->and($watch->nextSyncAt?->format(DATE_ATOM))->toBe('2026-08-28T10:05:00+00:00');
});

it('replays migration and coalesces duplicate webhook and poll triggers without duplicate work', function (): void {
    $installation = repositoryWatchInstallation();
    $legacy = adaptRepositoryWatch($installation);
    $watches = app(RepositoryWatches::class);
    $watch = $watches->import($legacy);
    $replay = $watches->import($legacy);
    $trigger = hash('sha256', 'acme/widget|PR_node_42|2026-08-28T11:00:00Z');

    expect($replay->id)->toBe($watch->id)
        ->and($watches->claimTrigger($watch, $trigger, new DateTimeImmutable('2026-08-28T11:00:01Z'), 'run:poll'))->toBeTrue()
        ->and($watches->claimTrigger($watch, $trigger, new DateTimeImmutable('2026-08-28T11:00:02Z'), 'run:webhook'))->toBeFalse()
        ->and(DB::table('aleph_repository_watches')->count())->toBe(1)
        ->and(DB::table('aleph_repository_watch_triggers')->count())->toBe(1);
});

it('preserves disabled error backoff due and successful checkpoint stories explicitly', function (): void {
    $installation = repositoryWatchInstallation();
    $watches = app(RepositoryWatches::class);
    $disabled = $watches->import(adaptRepositoryWatch($installation, ['enabled' => false]));

    expect($disabled->status(new DateTimeImmutable('2026-08-28T10:10:00Z')))->toBe(RepositoryWatchStatus::Disabled);

    $enabled = $watches->setEnabled($disabled, true);
    $failed = $watches->recordFailure(
        $enabled,
        'GitHub secondary rate limit.',
        new DateTimeImmutable('2026-08-28T10:10:00Z'),
        new DateTimeImmutable('2026-08-28T10:40:00Z'),
    );

    expect($failed->status(new DateTimeImmutable('2026-08-28T10:20:00Z')))->toBe(RepositoryWatchStatus::Backoff)
        ->and($watches->due(new DateTimeImmutable('2026-08-28T10:20:00Z'), 10))->toBe([])
        ->and($failed->status(new DateTimeImmutable('2026-08-28T10:41:00Z')))->toBe(RepositoryWatchStatus::Error)
        ->and($watches->due(new DateTimeImmutable('2026-08-28T10:41:00Z'), 10))->toHaveCount(1);

    $succeeded = $watches->recordSuccess(
        $failed,
        ['graphql_cursor' => 'cursor-4'],
        'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        new DateTimeImmutable('2026-08-28T10:45:00Z'),
        true,
    );

    expect($succeeded->lastError)->toBeNull()
        ->and($succeeded->backoffUntil)->toBeNull()
        ->and($succeeded->checkpoint)->toBe(['graphql_cursor' => 'cursor-4'])
        ->and($succeeded->headReference)->toBe('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')
        ->and($succeeded->backfillCompletedAt?->format(DATE_ATOM))->toBe('2026-08-28T10:45:00+00:00')
        ->and($succeeded->nextSyncAt?->format(DATE_ATOM))->toBe('2026-08-28T10:50:00+00:00')
        ->and($succeeded->status(new DateTimeImmutable('2026-08-28T10:46:00Z')))->toBe(RepositoryWatchStatus::Scheduled);
});

it('keeps provider payloads and repository history outside the portable watch and retains Landing persistence', function (): void {
    $installation = repositoryWatchInstallation();
    $watch = app(RepositoryWatches::class)->import(adaptRepositoryWatch($installation));
    $serialized = serialize($watch);
    $migrations = implode("\n", array_map(
        static fn (string $path): string => (string) file_get_contents($path),
        glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [],
    ));

    expect($serialized)->not->toContain('App\\')
        ->and($serialized)->not->toContain('Github\\')
        ->and($serialized)->not->toContain('pull_requests')
        ->and($serialized)->not->toContain('commits')
        ->and($serialized)->not->toContain('files')
        ->and($migrations)->not->toContain("dropIfExists('repo_watches')")
        ->and($migrations)->not->toContain('App\\Models\\RepoWatch');
});
