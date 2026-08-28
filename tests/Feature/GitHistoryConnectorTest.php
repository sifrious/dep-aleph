<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Connector\Capability as ConnectorCapability;
use Sifrious\Aleph\Connector\ConnectorDispatcher;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\Git\GitBlameRange;
use Sifrious\Aleph\Connector\Git\GitCommit;
use Sifrious\Aleph\Connector\Git\GitHistoryConnector;
use Sifrious\Aleph\Connector\Git\GitRepository;
use Sifrious\Aleph\Connector\Git\GitRepositorySource;
use Sifrious\Aleph\Connector\Git\GitRepositorySources;
use Sifrious\Aleph\Connector\Git\GitSnapshot;
use Sifrious\Aleph\Connector\Git\GitTreeEntry;
use Sifrious\Aleph\Connector\Git\ImportGitHistory;
use Sifrious\Aleph\Connector\Values\OperationRequest;
use Sifrious\Aleph\Envelope\ObservationMetadata;
use Sifrious\Aleph\Ingestion\Capability as IngestionCapability;
use Sifrious\Aleph\Ingestion\IngestionCheckpoints;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\RunStatus;
use Sifrious\Aleph\Ingestion\SourceStreams;
use Sifrious\Aleph\Ingestion\SyncStrategy;
use Sifrious\Funes\Persistence\ObservationStore;

const GIT_HEAD_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const GIT_HEAD_C = 'cccccccccccccccccccccccccccccccccccccccc';
const GIT_BLOB_ONE = '1111111111111111111111111111111111111111';
const GIT_BLOB_TWO = '2222222222222222222222222222222222222222';
const GIT_BLOB_THREE = '3333333333333333333333333333333333333333';

abstract class FixtureGitSource implements GitRepositorySource
{
    public int $snapshots = 0;

    public function repository(): GitRepository
    {
        return new GitRepository('git:repository/acme/widget', 'Acme Widget', 'https://example.test/acme/widget.git');
    }

    public function snapshot(string $ref, ?string $previousHeadSha): GitSnapshot
    {
        $this->snapshots++;

        if ($previousHeadSha === null) {
            return new GitSnapshot(
                ref: $ref,
                headSha: GIT_HEAD_A,
                capturedAt: new DateTimeImmutable('2026-08-28T10:00:00+00:00'),
                previousHeadSha: null,
                previousIsAncestor: true,
                commits: [$this->commit(GIT_HEAD_A, [], 'Initial import', '2026-08-28T10:00:00+00:00')],
                previousTree: [],
                tree: [
                    new GitTreeEntry('src/OldName.php', GIT_BLOB_ONE, content: '<?php echo 1;'),
                    new GitTreeEntry('src/Deleted.php', GIT_BLOB_TWO, content: '<?php echo 2;'),
                ],
                diff: 'initial tree',
                blame: [new GitBlameRange('src/OldName.php', 1, 1, GIT_HEAD_A, 'Ada', 'ada@example.test')],
            );
        }

        $currentTree = [
            new GitTreeEntry('src/NewName.php', GIT_BLOB_ONE, content: '<?php echo 1;'),
            new GitTreeEntry('src/Added.php', GIT_BLOB_THREE, content: '<?php echo 3;'),
        ];

        if ($previousHeadSha === GIT_HEAD_C) {
            return new GitSnapshot(
                ref: $ref,
                headSha: GIT_HEAD_C,
                capturedAt: new DateTimeImmutable('2026-08-28T11:00:00+00:00'),
                previousHeadSha: GIT_HEAD_C,
                previousIsAncestor: true,
                commits: [],
                previousTree: $currentTree,
                tree: $currentTree,
                diff: '',
                blame: [new GitBlameRange('src/NewName.php', 1, 1, GIT_HEAD_A, 'Ada', 'ada@example.test')],
            );
        }

        return new GitSnapshot(
            ref: $ref,
            headSha: GIT_HEAD_C,
            capturedAt: new DateTimeImmutable('2026-08-28T11:00:00+00:00'),
            previousHeadSha: GIT_HEAD_A,
            previousIsAncestor: false,
            commits: [$this->commit(GIT_HEAD_C, ['bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'], 'Rewrite history', '2026-08-28T11:00:00+00:00')],
            previousTree: [
                new GitTreeEntry('src/OldName.php', GIT_BLOB_ONE),
                new GitTreeEntry('src/Deleted.php', GIT_BLOB_TWO),
            ],
            tree: $currentTree,
            diff: "rename src/OldName.php src/NewName.php\ndelete src/Deleted.php\nadd src/Added.php",
            blame: [new GitBlameRange('src/NewName.php', 1, 1, GIT_HEAD_A, 'Ada', 'ada@example.test')],
        );
    }

    /**
     * @param  list<string>  $parents
     */
    private function commit(string $sha, array $parents, string $message, string $at): GitCommit
    {
        return new GitCommit(
            $sha,
            $parents,
            'Ada Lovelace',
            'ada@example.test',
            new DateTimeImmutable($at),
            new DateTimeImmutable($at),
            $message,
        );
    }
}

final class LocalFixtureGitSource extends FixtureGitSource {}

final class AlternateFixtureGitSource extends FixtureGitSource {}

final class FailingFixtureGitSource extends FixtureGitSource
{
    public function snapshot(string $ref, ?string $previousHeadSha): GitSnapshot
    {
        throw new RuntimeException('The Git source became unavailable.');
    }
}

function runGitImport(GitHistoryConnector $connector, string $installationId, string $streamId, int $expectedVersion): array
{
    $runs = app(IngestionRuns::class);
    $run = $runs->start(
        'git:repository/acme/widget',
        IngestionCapability::IncrementalSync,
        ['ref' => 'refs/heads/main'],
        connectorId: 'git-history',
        sourceInstallationId: $installationId,
    );
    $attempt = $runs->beginAttempt($run);
    $result = app(ConnectorDispatcher::class)->dispatch(
        $connector->id(),
        ConnectorCapability::SyncsIncrementally,
        new OperationRequest('git:repository/acme/widget', [
            'ref' => 'refs/heads/main',
            'stream_id' => $streamId,
            'run_id' => $run->id,
            'attempt_id' => $attempt->id,
            'expected_checkpoint_version' => $expectedVersion,
        ]),
    );

    return [$run, $result];
}

it('preserves force pushes renames deletions diffs and blame while replaying one ref idempotently', function (): void {
    $sources = app(GitRepositorySources::class);
    $local = new LocalFixtureGitSource;
    $sources->register($local);
    $connector = new GitHistoryConnector(app(ImportGitHistory::class));
    app(ConnectorRegistry::class)->register($connector);
    $installation = app(ConnectorInstallations::class)->create(
        $connector,
        'Acme Widget local',
        externalAccountId: 'local:acme-widget',
        funesSourceAccountId: 'source-account:git-acme-widget',
    );
    $stream = app(SourceStreams::class)->create(
        $installation->id,
        'refs/heads/main',
        'repository',
        'git:repository/acme/widget',
        SyncStrategy::HighWater,
    );
    [, $first] = runGitImport($connector, $installation->id, $stream->id, 0);
    $afterFirst = DB::table('funes_observations')->count();
    [, $rewrite] = runGitImport($connector, $installation->id, $stream->id, 1);
    $afterRewrite = DB::table('funes_observations')->count();
    $alternate = new AlternateFixtureGitSource;
    $sources->register($alternate);
    [, $replay] = runGitImport($connector, $installation->id, $stream->id, 2);
    $afterReplay = DB::table('funes_observations')->count();
    $sources->register(new FailingFixtureGitSource);
    [$failedRun, $failure] = runGitImport($connector, $installation->id, $stream->id, 3);
    $payloads = DB::table('funes_observations')
        ->join('funes_payloads', 'funes_payloads.hash', '=', 'funes_observations.payload_hash')
        ->pluck('funes_payloads.contents')
        ->map(static fn (string $payload): array => json_decode($payload, true, 512, JSON_THROW_ON_ERROR));
    $deleted = $payloads->first(static fn (array $payload): bool => ($payload['kind'] ?? null) === 'deleted');
    $renamed = $payloads->first(static fn (array $payload): bool => ($payload['kind'] ?? null) === 'renamed');
    $refPayload = $payloads->first(static fn (array $payload): bool => ($payload['force_pushed'] ?? false) === true);
    $deletionObservation = DB::table('funes_observations')
        ->join('funes_payloads', 'funes_payloads.hash', '=', 'funes_observations.payload_hash')
        ->where('funes_payloads.contents', json_encode($deleted, JSON_THROW_ON_ERROR))
        ->value('funes_observations.id');
    $deletionMetadata = ObservationMetadata::aleph(app(ObservationStore::class)->get((string) $deletionObservation));
    $checkpoint = app(IngestionCheckpoints::class)->latest($stream, IngestionCapability::IncrementalSync, 'refs/heads/main');

    expect($first->successful)->toBeTrue()
        ->and($rewrite->successful)->toBeTrue()
        ->and($rewrite->metadata['force_pushed'])->toBeTrue()
        ->and($afterRewrite)->toBeGreaterThan($afterFirst)
        ->and($deleted['path'])->toBe('src/Deleted.php')
        ->and($deleted['previous_blob_sha'])->toBe(GIT_BLOB_TWO)
        ->and($renamed['path'])->toBe('src/NewName.php')
        ->and($renamed['previous_path'])->toBe('src/OldName.php')
        ->and($refPayload['previous_head_sha'])->toBe(GIT_HEAD_A)
        ->and($deletionMetadata['event_type'])->toBe('git.change')
        ->and($deletionMetadata['provider_revision'])->toBe(GIT_HEAD_C)
        ->and($replay->successful)->toBeTrue()
        ->and($afterReplay)->toBe($afterRewrite)
        ->and($failure->successful)->toBeFalse()
        ->and($failure->error)->toContain('unavailable')
        ->and(app(IngestionRuns::class)->find($failedRun->id)?->status)->toBe(RunStatus::Failed)
        ->and(app(IngestionRuns::class)->find($failedRun->id)?->failure?->retryable)->toBeTrue()
        ->and(DB::table('funes_observations')->count())->toBe($afterReplay)
        ->and($checkpoint?->version)->toBe(3)
        ->and($checkpoint?->value->value)->toBe(GIT_HEAD_C)
        ->and($local->snapshots)->toBe(2)
        ->and($alternate->snapshots)->toBe(1);
});

it('advertises only generic backfill and incremental capabilities', function (): void {
    $connector = new GitHistoryConnector(app(ImportGitHistory::class));
    app(ConnectorRegistry::class)->register($connector);
    $manifest = app(ConnectorRegistry::class)->manifest($connector->id());

    expect($manifest->capabilityIds())->toBe(['history.backfill', 'sync.incremental'])
        ->and($manifest->availableOperations())->toBe(['history.backfill', 'sync.incremental'])
        ->and($manifest->configuration->fields)->toBe([]);
});
