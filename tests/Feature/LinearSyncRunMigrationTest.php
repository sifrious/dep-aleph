<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\LandingLinearSyncRunAdapter;
use Sifrious\Aleph\Ingestion\LegacyRunIdentity;
use Sifrious\Aleph\Ingestion\LegacySyncRun;
use Sifrious\Aleph\Ingestion\LinearRunQueries;
use Sifrious\Aleph\Ingestion\LinearRunScope;
use Sifrious\Aleph\Ingestion\RunStatus;

function landingLinearRunFixture(array $overrides = []): array
{
    return array_replace([
        'id' => 117,
        'scope' => 'all',
        'label' => 'Sync Linear data',
        'status' => 'succeeded',
        'targets' => [[
            'workspace_id' => 'workspace-1',
            'project_id' => 'project-1',
            'streams' => ['projects', 'issues', 'project_updates'],
        ]],
        'checkpoint' => [
            'projects_cursor' => 'project-cursor-3',
            'issues_cursor' => 'issue-cursor-8',
            'updates_cursor' => 'update-cursor-2',
        ],
        'stats' => [
            'projects' => 1,
            'issues_seen' => 12,
            'updates' => 3,
            'ignored' => 'not-numeric',
        ],
        'error' => null,
        'started_at' => '2026-08-28T10:00:00+00:00',
        'finished_at' => '2026-08-28T10:03:00+00:00',
    ], $overrides);
}

function adaptLinearRun(array $row = [], array $accepted = []): LegacySyncRun
{
    return (new LandingLinearSyncRunAdapter)->adapt(
        landingLinearRunFixture($row),
        'linear:workspace/acme',
        ['linear:project/project-1'],
        ['linear:issue/issue-8', 'linear:project-update/update-2'],
        $accepted,
        'linear-provider-run-117',
        '01K4LINEARINSTALLATION000001',
    );
}

it('migrates Linear runs with stable run source and installation identities', function (): void {
    $run = app(IngestionRuns::class)->import(adaptLinearRun());

    expect($run->id)->toBe(LegacyRunIdentity::for('landing:linear-sync-run/117'))
        ->and($run->connectorId)->toBe('linear')
        ->and($run->sourceReference)->toBe('linear:workspace/acme')
        ->and($run->sourceInstallationId)->toBe('01K4LINEARINSTALLATION000001');
});

it('restores workspace project provider reconciliation and per-stream checkpoints', function (): void {
    $run = app(IngestionRuns::class)->import(adaptLinearRun());
    $projection = app(LinearRunQueries::class)->find($run->id);

    expect($projection?->scope)->toBe(LinearRunScope::All)
        ->and($projection?->workspaceReference)->toBe('linear:workspace/acme')
        ->and($projection?->projectReferences)->toBe(['linear:project/project-1'])
        ->and($projection?->providerRunId)->toBe('linear-provider-run-117')
        ->and($projection?->providerReconciliationIds)->toBe([
            'linear:issue/issue-8',
            'linear:project-update/update-2',
        ])->and($projection?->ingestion->run->checkpoint)->toBe([
            'projects_cursor' => 'project-cursor-3',
            'issues_cursor' => 'issue-cursor-8',
            'updates_cursor' => 'update-cursor-2',
        ])->and($projection?->targets[0]['streams'])->toBe(['projects', 'issues', 'project_updates']);
});

it('uses shared run retry resume attempt status timing and counters', function (): void {
    $runs = app(IngestionRuns::class);
    $run = $runs->import(adaptLinearRun([
        'status' => 'failed',
        'error' => 'Linear rate limit.',
        'retryable' => true,
        'finished_at' => '2026-08-28T10:01:00+00:00',
    ]));
    $attempt = $runs->beginAttempt($run);
    $projection = app(LinearRunQueries::class)->find($run->id);

    expect($attempt->number)->toBe(1)
        ->and($attempt->checkpoint)->toBe($run->checkpoint)
        ->and($projection?->ingestion->run->status)->toBe(RunStatus::Running)
        ->and($projection?->ingestion->run->stats)->toBe([
            'projects' => 1,
            'issues_seen' => 12,
            'updates' => 3,
        ])->and($projection?->ingestion->attempts)->toHaveCount(1);
});

it('keeps Linear SDK and Landing model values outside public contracts', function (): void {
    $run = app(IngestionRuns::class)->import(adaptLinearRun());
    $serialized = serialize(app(LinearRunQueries::class)->find($run->id));

    expect($serialized)->not->toContain('App\\')
        ->and($serialized)->not->toContain('LinearSyncRun')
        ->and($serialized)->not->toContain('Linear\\Client')
        ->and($serialized)->not->toContain('GraphQL');
});

it('retains only stable Funes references to imported Linear history', function (): void {
    $run = app(IngestionRuns::class)->import(adaptLinearRun([], [
        'funes:observation/01K4LINEARPROJECT',
        'funes:observation/01K4LINEARISSUE',
    ]));
    $projection = app(LinearRunQueries::class)->find($run->id)?->toArray();

    expect($projection['run']['accepted_references'])->toBe([
        'funes:observation/01K4LINEARPROJECT',
        'funes:observation/01K4LINEARISSUE',
    ])->and($projection)->not->toHaveKey('issues')
        ->and($projection)->not->toHaveKey('project_updates');
});

it('preserves every implemented Landing Linear run status story unchanged', function (
    string $status,
    RunStatus $expected,
): void {
    $run = app(IngestionRuns::class)->import(adaptLinearRun([
        'id' => 'linear-'.$status,
        'status' => $status,
        'finished_at' => in_array($status, ['succeeded', 'failed'], true) ? '2026-08-28T10:03:00+00:00' : null,
    ]));
    $projection = app(LinearRunQueries::class)->find($run->id);

    expect($projection?->scope)->toBe(LinearRunScope::All)
        ->and($projection?->ingestion->run->status)->toBe($expected)
        ->and($projection?->ingestion->run->parameters['label'])->toBe('Sync Linear data');
})->with([
    'pending global dispatch' => ['pending', RunStatus::Pending],
    'running global sync' => ['running', RunStatus::Running],
    'successful global sync' => ['succeeded', RunStatus::Completed],
    'failed global sync' => ['failed', RunStatus::Failed],
]);

it('replays migration idempotently and retains Landing persistence through cutover reconciliation', function (): void {
    $legacy = adaptLinearRun();
    $runs = app(IngestionRuns::class);
    $first = $runs->import($legacy);
    $replay = $runs->import($legacy);
    $latest = app(LinearRunQueries::class)->latest('linear:workspace/acme');

    expect($replay->id)->toBe($first->id)
        ->and($latest?->toArray())->toBe(app(LinearRunQueries::class)->find($first->id)?->toArray())
        ->and(DB::table('aleph_ingestion_runs')->count())->toBe(1);

    $migrations = implode("\n", array_map(
        static fn (string $path): string => (string) file_get_contents($path),
        glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [],
    ));

    expect($migrations)->not->toContain("dropIfExists('linear_sync_runs')")
        ->and($migrations)->not->toContain('App\\Models\\LinearSyncRun');
});
