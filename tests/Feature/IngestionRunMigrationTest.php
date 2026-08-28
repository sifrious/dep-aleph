<?php

declare(strict_types=1);

use Sifrious\Aleph\Ingestion\Capability;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\LandingSyncRunAdapter;
use Sifrious\Aleph\Ingestion\LegacyRunIdentity;
use Sifrious\Aleph\Ingestion\LegacySyncRun;
use Sifrious\Aleph\Ingestion\RunCompleteness;
use Sifrious\Aleph\Ingestion\RunFailure;
use Sifrious\Aleph\Ingestion\RunStatus;

function landingSyncRunFixture(): array
{
    return [
        'id' => 417,
        'repository_reference' => 'github:R_kgDOExample',
        'repo_watch_reference' => 'landing:repo-watch/12',
        'status' => 'running',
        'current_step_key' => 'extract_symbols',
        'steps' => [
            ['key' => 'pull_prs', 'status' => 'done', 'current' => 4, 'total' => 4],
            ['key' => 'index_files', 'status' => 'active', 'group' => true, 'current' => 0, 'total' => 0],
            ['key' => 'extract_symbols', 'status' => 'active', 'current' => 9, 'total' => 20],
        ],
        'label' => 'octocat/hello-world',
        'branch' => 'main',
        'error' => null,
        'started_at' => '2026-08-28T08:00:00+00:00',
        'finished_at' => null,
    ];
}

it('migrates a Landing sync run with a deterministic id and source reference', function (): void {
    $legacy = (new LandingSyncRunAdapter)->adapt(landingSyncRunFixture(), 'github:repository/R_kgDOExample');

    $run = app(IngestionRuns::class)->import($legacy);

    expect($run->id)->toBe(LegacyRunIdentity::for('landing:sync-run/417'))
        ->and($run->legacyReference)->toBe('landing:sync-run/417')
        ->and($run->sourceReference)->toBe('github:repository/R_kgDOExample')
        ->and($run->connectorId)->toBe('github');
});

it('preserves checkpoint timing and source backed outcome', function (): void {
    $legacy = (new LandingSyncRunAdapter)->adapt(landingSyncRunFixture(), 'github:repository/R_kgDOExample');

    $run = app(IngestionRuns::class)->import($legacy);

    expect($run->status)->toBe(RunStatus::Running)
        ->and($run->checkpoint['current_step'])->toBe('extract_symbols')
        ->and($run->checkpoint['steps'])->toHaveCount(3)
        ->and($run->stats)->toBe([
            'steps_total' => 2,
            'steps_completed' => 1,
            'steps_failed' => 0,
        ])
        ->and($run->startedAt->format(DATE_ATOM))->toBe('2026-08-28T08:00:00+00:00')
        ->and($run->finishedAt)->toBeNull();
});

it('keeps a partial run explicit with its failure and accepted history references', function (): void {
    $legacy = new LegacySyncRun(
        legacyReference: 'landing:slack-sync-run/9',
        connectorId: 'slack',
        sourceReference: 'slack:workspace/T1',
        capability: Capability::Backfill,
        status: RunStatus::Partial,
        completeness: RunCompleteness::Partial,
        parameters: ['channel' => 'C1'],
        checkpoint: ['cursor' => 'next-page'],
        stats: ['accepted' => 10, 'failed' => 1],
        failure: new RunFailure('rate_limited', 'retry later', true, ['partition' => 'C1']),
        acceptedReferences: ['funes:observation/01K4ACCEPTED'],
        startedAt: new DateTimeImmutable('2026-08-28T08:00:00+00:00'),
        finishedAt: new DateTimeImmutable('2026-08-28T08:05:00+00:00'),
    );

    $run = app(IngestionRuns::class)->import($legacy);

    expect($run->status)->toBe(RunStatus::Partial)
        ->and($run->completeness)->toBe(RunCompleteness::Partial)
        ->and($run->failure?->toArray())->toBe([
            'kind' => 'rate_limited',
            'message' => 'retry later',
            'retryable' => true,
            'details' => ['partition' => 'C1'],
        ])
        ->and($run->acceptedReferences)->toBe(['funes:observation/01K4ACCEPTED']);
});

it('replays a legacy migration without changing identity or duplicating the run', function (): void {
    $legacy = (new LandingSyncRunAdapter)->adapt(landingSyncRunFixture(), 'github:repository/R_kgDOExample');
    $runs = app(IngestionRuns::class);

    $first = $runs->import($legacy);
    $replayed = $runs->import($legacy);

    expect($replayed->id)->toBe($first->id)
        ->and(DB::table('aleph_ingestion_runs')->count())->toBe(1);
});

it('replays a launch idempotency key as the original run', function (): void {
    $runs = app(IngestionRuns::class);

    $first = $runs->start(
        'slack:workspace/T1',
        Capability::Backfill,
        ['channel' => 'C1'],
        connectorId: 'slack',
        idempotencyKey: 'launch:slack:T1:C1',
        checkpoint: ['cursor' => null],
    );
    $replayed = $runs->start(
        'slack:workspace/T1',
        Capability::Backfill,
        ['channel' => 'changed-input-is-not-reapplied'],
        connectorId: 'slack',
        idempotencyKey: 'launch:slack:T1:C1',
    );

    expect($replayed->id)->toBe($first->id)
        ->and($replayed->parameters)->toBe(['channel' => 'C1'])
        ->and(DB::table('aleph_ingestion_runs')->count())->toBe(1);
});

it('serializes a provider neutral run without Landing model types', function (): void {
    $run = app(IngestionRuns::class)->import(
        (new LandingSyncRunAdapter)->adapt(landingSyncRunFixture(), 'github:repository/R_kgDOExample')
    );

    expect(array_keys($run->toArray()))->toBe([
        'id',
        'connector',
        'source_installation',
        'source',
        'capability',
        'trigger',
        'requested_by',
        'authorization_decision',
        'status',
        'completeness',
        'parameters',
        'checkpoint',
        'stats',
        'failure',
        'accepted_references',
        'legacy_reference',
        'requested_at',
        'started_at',
        'finished_at',
    ])
        ->and($run->toArray()['capability'])->toBe('sync.incremental')
        ->and(serialize($run))->not->toContain('App\\Models');
});

it('keeps Landing persistence in place until a consuming host reconciles cutover', function (): void {
    $migrations = implode("\n", array_map(
        static fn (string $path): string => (string) file_get_contents($path),
        glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [],
    ));

    expect($migrations)->not->toContain("dropIfExists('sync_runs')")
        ->and($migrations)->not->toContain('App\\Models\\SyncRun');
});
