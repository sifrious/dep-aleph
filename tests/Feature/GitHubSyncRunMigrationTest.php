<?php

declare(strict_types=1);

use Sifrious\Aleph\Ingestion\GitHubRunQueries;
use Sifrious\Aleph\Ingestion\GitHubRunScope;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\LandingGitHubSyncRunAdapter;
use Sifrious\Aleph\Ingestion\LegacyRunIdentity;
use Sifrious\Aleph\Ingestion\LegacySyncRun;
use Sifrious\Aleph\Ingestion\RunStatus;

function landingGitHubRunFixture(array $overrides = []): array
{
    return array_replace([
        'id' => 91,
        'project_id' => 4,
        'repo_watch_id' => 12,
        'scope' => 'watch',
        'label' => 'Sync watched repo: acme/widget @main',
        'status' => 'succeeded',
        'targets' => [[
            'owner' => 'acme',
            'name' => 'widget',
            'repository_id' => 8,
            'state' => 'all',
            'branch' => 'main',
        ]],
        'checkpoint' => ['graphql_cursor' => 'cursor-3', 'ref' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
        'stats' => ['repositories' => 1, 'pull_requests' => 12, 'comments' => 31, 'ignored' => 'not-numeric'],
        'error' => null,
        'started_at' => '2026-08-28T10:00:00+00:00',
        'finished_at' => '2026-08-28T10:03:00+00:00',
    ], $overrides);
}

function adaptGitHubRun(array $row = [], array $accepted = []): LegacySyncRun
{
    return (new LandingGitHubSyncRunAdapter)->adapt(
        landingGitHubRunFixture($row),
        'github:account/acme',
        ['git:repository/acme/widget'],
        ['github:repository/R_123', 'github:pull-request/PR_456'],
        $accepted,
        'repo-watch:acme/widget',
        '01K4GITHUBINSTALLATION000001',
    );
}

it('migrates GitHub runs with stable run account and repository identities', function (): void {
    $run = app(IngestionRuns::class)->import(adaptGitHubRun());

    expect($run->id)->toBe(LegacyRunIdentity::for('landing:github-sync-run/91'))
        ->and($run->connectorId)->toBe('github')
        ->and($run->sourceReference)->toBe('github:account/acme')
        ->and($run->sourceInstallationId)->toBe('01K4GITHUBINSTALLATION000001');
});

it('preserves reconciliation ids checkpoints targets and watch scope in the GitHub projection', function (): void {
    $run = app(IngestionRuns::class)->import(adaptGitHubRun());
    $projection = app(GitHubRunQueries::class)->find($run->id);

    expect($projection?->scope)->toBe(GitHubRunScope::Watch)
        ->and($projection?->accountReference)->toBe('github:account/acme')
        ->and($projection?->repositoryReferences)->toBe(['git:repository/acme/widget'])
        ->and($projection?->repoWatchReference)->toBe('repo-watch:acme/widget')
        ->and($projection?->providerReconciliationIds)->toBe([
            'github:repository/R_123',
            'github:pull-request/PR_456',
        ])
        ->and($projection?->targets[0]['branch'])->toBe('main')
        ->and($projection?->ingestion->run->checkpoint)->toBe([
            'graphql_cursor' => 'cursor-3',
            'ref' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ]);
});

it('uses shared run retry and attempt semantics', function (): void {
    $runs = app(IngestionRuns::class);
    $run = $runs->import(adaptGitHubRun([
        'status' => 'failed',
        'error' => 'GitHub secondary rate limit.',
        'retryable' => true,
        'finished_at' => '2026-08-28T10:01:00+00:00',
    ]));
    $attempt = $runs->beginAttempt($run);
    $projection = app(GitHubRunQueries::class)->find($run->id);

    expect($attempt->number)->toBe(1)
        ->and($attempt->checkpoint)->toBe($run->checkpoint)
        ->and($projection?->ingestion->run->status)->toBe(RunStatus::Running)
        ->and($projection?->ingestion->attempts)->toHaveCount(1);
});

it('keeps GitHub SDK and Landing model values outside public contracts', function (): void {
    $run = app(IngestionRuns::class)->import(adaptGitHubRun());
    $serialized = serialize(app(GitHubRunQueries::class)->find($run->id));

    expect($serialized)->not->toContain('App\\')
        ->and($serialized)->not->toContain('GithubSyncRun')
        ->and($serialized)->not->toContain('Github\\')
        ->and($serialized)->not->toContain('GitHub\\');
});

it('retains only stable Funes references to imported GitHub history', function (): void {
    $run = app(IngestionRuns::class)->import(adaptGitHubRun([], [
        'funes:observation/01K4GITHUBREPO',
        'funes:observation/01K4GITHUBPR',
    ]));
    $projection = app(GitHubRunQueries::class)->find($run->id)?->toArray();

    expect($projection['run']['accepted_references'])->toBe([
        'funes:observation/01K4GITHUBREPO',
        'funes:observation/01K4GITHUBPR',
    ])
        ->and($projection)->not->toHaveKey('pull_requests')
        ->and($projection)->not->toHaveKey('comments');
});

it('preserves every implemented Landing GitHub run scope and status story', function (
    string $scope,
    string $status,
    RunStatus $expected,
): void {
    $run = app(IngestionRuns::class)->import(adaptGitHubRun([
        'id' => $scope.'-'.$status,
        'scope' => $scope,
        'status' => $status,
        'finished_at' => in_array($status, ['succeeded', 'failed'], true) ? '2026-08-28T10:03:00+00:00' : null,
    ]));
    $projection = app(GitHubRunQueries::class)->find($run->id);

    expect($projection?->scope)->toBe(GitHubRunScope::from($scope))
        ->and($projection?->ingestion->run->status)->toBe($expected);
})->with([
    'pending global dispatch' => ['all', 'pending', RunStatus::Pending],
    'running project sync' => ['project', 'running', RunStatus::Running],
    'successful repository sync' => ['repo', 'succeeded', RunStatus::Completed],
    'failed pull request sync' => ['pull-request', 'failed', RunStatus::Failed],
    'successful watched repository sync' => ['watch', 'succeeded', RunStatus::Completed],
]);

it('replays migration without duplication and leaves Landing persistence for reconciled cutover', function (): void {
    $legacy = adaptGitHubRun();
    $runs = app(IngestionRuns::class);

    expect($runs->import($legacy)->id)->toBe($runs->import($legacy)->id)
        ->and(DB::table('aleph_ingestion_runs')->count())->toBe(1);

    $migrations = implode("\n", array_map(
        static fn (string $path): string => (string) file_get_contents($path),
        glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [],
    ));

    expect($migrations)->not->toContain("dropIfExists('github_sync_runs')")
        ->and($migrations)->not->toContain('App\\Models\\GithubSyncRun');
});
