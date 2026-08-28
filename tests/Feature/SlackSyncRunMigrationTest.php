<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\LandingSlackSyncRunAdapter;
use Sifrious\Aleph\Ingestion\LegacyRunIdentity;
use Sifrious\Aleph\Ingestion\LegacySyncRun;
use Sifrious\Aleph\Ingestion\RunStatus;
use Sifrious\Aleph\Ingestion\SlackRunQueries;
use Sifrious\Aleph\Ingestion\SlackRunScope;

function landingSlackRunFixture(array $overrides = []): array
{
    return array_replace([
        'id' => 205,
        'scope' => 'channel',
        'label' => 'Sync channel history: engineering',
        'status' => 'succeeded',
        'targets' => [[
            'channel_id' => 17,
            'slack_id' => 'C123',
        ]],
        'stats' => [
            'messages_upserted' => 42,
            'replies_upserted' => 7,
            'files_seen' => 3,
            'ignored' => 'not-numeric',
        ],
        'error' => null,
        'started_at' => '2026-08-28T10:00:00+00:00',
        'finished_at' => '2026-08-28T10:03:00+00:00',
    ], $overrides);
}

function adaptSlackRun(array $row = [], array $accepted = []): LegacySyncRun
{
    return (new LandingSlackSyncRunAdapter)->adapt(
        landingSlackRunFixture($row),
        'slack:workspace/T123',
        ['slack:channel/C123'],
        ['slack:message/C123/1787911200.000100'],
        [
            'cursor' => 'dXNlcjpVMDYxTkZUVDI=',
            'oldest' => '1787910000.000000',
            'high_water' => '1787911200.000100',
        ],
        $accepted,
        'slack-provider-run-205',
        '01M14SLACKINSTALLATION0001',
    );
}

it('migrates Slack runs with stable shared run source and installation identities', function (): void {
    $run = app(IngestionRuns::class)->import(adaptSlackRun());

    expect($run->id)->toBe(LegacyRunIdentity::for('landing:slack-sync-run/205'))
        ->and($run->connectorId)->toBe('slack')
        ->and($run->sourceReference)->toBe('slack:workspace/T123')
        ->and($run->sourceInstallationId)->toBe('01M14SLACKINSTALLATION0001');
});

it('restores workspace channel reconciliation target and cursor state', function (): void {
    $run = app(IngestionRuns::class)->import(adaptSlackRun());
    $projection = app(SlackRunQueries::class)->find($run->id);

    expect($projection?->scope)->toBe(SlackRunScope::Channel)
        ->and($projection?->workspaceReference)->toBe('slack:workspace/T123')
        ->and($projection?->channelReferences)->toBe(['slack:channel/C123'])
        ->and($projection?->providerRunId)->toBe('slack-provider-run-205')
        ->and($projection?->providerReconciliationIds)->toBe(['slack:message/C123/1787911200.000100'])
        ->and($projection?->targets)->toBe([['channel_id' => 17, 'slack_id' => 'C123']])
        ->and($projection?->ingestion->run->checkpoint)->toBe([
            'cursor' => 'dXNlcjpVMDYxTkZUVDI=',
            'oldest' => '1787910000.000000',
            'high_water' => '1787911200.000100',
        ]);
});

it('uses the shared lifecycle for retry resume timing counters and failures', function (): void {
    $runs = app(IngestionRuns::class);
    $run = $runs->import(adaptSlackRun([
        'status' => 'failed',
        'error' => 'Slack rate limit.',
        'retryable' => true,
        'finished_at' => '2026-08-28T10:01:00+00:00',
    ]));
    $attempt = $runs->beginAttempt($run);
    $projection = app(SlackRunQueries::class)->find($run->id);

    expect($attempt->number)->toBe(1)
        ->and($attempt->checkpoint)->toBe($run->checkpoint)
        ->and($projection?->ingestion->run->status)->toBe(RunStatus::Running)
        ->and($projection?->ingestion->run->stats)->toBe([
            'messages_upserted' => 42,
            'replies_upserted' => 7,
            'files_seen' => 3,
        ])->and($projection?->ingestion->attempts)->toHaveCount(1);
});

it('keeps Slack SDK and Landing model values outside public contracts', function (): void {
    $run = app(IngestionRuns::class)->import(adaptSlackRun());
    $serialized = serialize(app(SlackRunQueries::class)->find($run->id));

    expect($serialized)->not->toContain('App\\')
        ->and($serialized)->not->toContain('SlackSyncRun')
        ->and($serialized)->not->toContain('Slack\\WebClient')
        ->and($serialized)->not->toContain('Psr\\Http');
});

it('retains only stable Funes references to imported Slack history', function (): void {
    $run = app(IngestionRuns::class)->import(adaptSlackRun([], [
        'funes:observation/01M14SLACKMESSAGE',
        'funes:observation/01M14SLACKFILE',
    ]));
    $projection = app(SlackRunQueries::class)->find($run->id)?->toArray();

    expect($projection['run']['accepted_references'])->toBe([
        'funes:observation/01M14SLACKMESSAGE',
        'funes:observation/01M14SLACKFILE',
    ])->and($projection)->not->toHaveKey('messages')
        ->and($projection)->not->toHaveKey('files');
});

it('preserves every implemented Landing Slack run status and scope story', function (string $status, string $scope, RunStatus $expected): void {
    $run = app(IngestionRuns::class)->import(adaptSlackRun([
        'id' => 'slack-'.$scope.'-'.$status,
        'scope' => $scope,
        'status' => $status,
        'finished_at' => in_array($status, ['succeeded', 'failed'], true) ? '2026-08-28T10:03:00+00:00' : null,
    ]));
    $projection = app(SlackRunQueries::class)->find($run->id);

    expect($projection?->scope)->toBe(SlackRunScope::from($scope))
        ->and($projection?->ingestion->run->status)->toBe($expected)
        ->and($projection?->ingestion->run->parameters['label'])->toBe('Sync channel history: engineering');
})->with([
    'pending channel sweep' => ['pending', 'channels', RunStatus::Pending],
    'running channel history' => ['running', 'channel', RunStatus::Running],
    'successful channel history' => ['succeeded', 'channel', RunStatus::Completed],
    'failed channel sweep' => ['failed', 'channels', RunStatus::Failed],
]);

it('replays migration idempotently and retains Landing persistence through reconciliation', function (): void {
    $legacy = adaptSlackRun();
    $runs = app(IngestionRuns::class);
    $first = $runs->import($legacy);
    $replay = $runs->import($legacy);
    $latest = app(SlackRunQueries::class)->latest('slack:workspace/T123');

    expect($replay->id)->toBe($first->id)
        ->and($latest?->toArray())->toBe(app(SlackRunQueries::class)->find($first->id)?->toArray())
        ->and(DB::table('aleph_ingestion_runs')->count())->toBe(1);

    $migrations = implode("\n", array_map(
        static fn (string $path): string => (string) file_get_contents($path),
        glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [],
    ));

    expect($migrations)->not->toContain("dropIfExists('slack_sync_runs')")
        ->and($migrations)->not->toContain('App\\Models\\SlackSyncRun');
});
