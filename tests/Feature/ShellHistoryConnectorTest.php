<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\Shell\AtuinHistoryAdapter;
use Sifrious\Aleph\Connector\Shell\ClaudeBashHistoryAdapter;
use Sifrious\Aleph\Connector\Shell\IngestShellHistory;
use Sifrious\Aleph\Connector\Shell\RedactionDecision;
use Sifrious\Aleph\Connector\Shell\ShellExecutionContext;
use Sifrious\Aleph\Connector\Shell\ShellHistoryConnector;
use Sifrious\Aleph\Connector\Shell\ShellHistoryScan;
use Sifrious\Aleph\Connector\Shell\ShellHistorySource;
use Sifrious\Aleph\Connector\Shell\ShellHistorySources;
use Sifrious\Aleph\Connector\Shell\ShellRedactionPolicy;
use Sifrious\Aleph\Connector\Shell\ZshHistoryAdapter;
use Sifrious\Aleph\Connector\Values\OperationRequest;

final class FixtureShellHistorySource implements ShellHistorySource
{
    public function __construct(
        private readonly string $reference,
        private readonly ShellHistoryScan $history,
    ) {}

    public function sourceReference(): string
    {
        return $this->reference;
    }

    public function scan(?string $cursor): ShellHistoryScan
    {
        return $this->history;
    }
}

function shellContext(string $host = 'workstation', string $user = 'mary'): ShellExecutionContext
{
    return new ShellExecutionContext($host, $user, 'zsh', '/workspace', 'session-1', 'environment:dev');
}

function shellConnectorInstallation(ShellHistoryConnector $connector, string $account): object
{
    return app(ConnectorInstallations::class)->create(
        $connector,
        $account,
        externalAccountId: $account,
        funesSourceAccountId: 'source-account:'.$account,
    );
}

function shellPayloads(): array
{
    return DB::table('funes_payloads')->pluck('contents')->map(
        static fn (string $contents): array => json_decode($contents, true, 512, JSON_THROW_ON_ERROR),
    )->all();
}

it('normalizes zsh Atuin and Claude Bash records with execution provenance', function (): void {
    $zsh = (new ZshHistoryAdapter)->adapt(": 1724842800:0;git status\nprintf 'hello world'\n", 'zsh:sha256:1', shellContext());
    $atuin = (new AtuinHistoryAdapter)->adapt([[
        'id' => 'atuin-1',
        'timestamp' => 1_724_842_800_000_000_000,
        'duration' => 2_500_000_000,
        'exit' => 0,
        'command' => 'composer test',
        'cwd' => '/workspace/aleph',
        'session' => 'atuin-session',
        'hostname' => 'laptop',
    ]], 'atuin:page:8', shellContext());
    $claude = (new ClaudeBashHistoryAdapter)->adapt([[
        'message_id' => 'message-1',
        'tool_use_id' => 'tool-1',
        'command' => 'php artisan test',
        'output' => "Exit code: 0\nPASS",
        'exit_code' => 0,
        'executed_at' => '2026-08-28T11:00:00Z',
    ]], 'claude:transcript:12', shellContext());

    expect($zsh)->toHaveCount(2)
        ->and($zsh[0]->executedAt?->getTimestamp())->toBe(1_724_842_800)
        ->and($zsh[1]->argv)->toBe(['printf', 'hello world'])
        ->and($atuin[0]->durationMilliseconds)->toBe(2500)
        ->and($atuin[0]->context->host)->toBe('laptop')
        ->and($atuin[0]->context->cwd)->toBe('/workspace/aleph')
        ->and($claude[0]->sourceRecordId)->toBe('message-1:tool-1')
        ->and($claude[0]->output)->toContain('PASS')
        ->and($claude[0]->context->environmentReference)->toBe('environment:dev');
});

it('redacts commands and output before Funes acceptance while retaining explicit policy evidence', function (): void {
    $secret = 'ghp_ABCDEFGHIJKLMNOPQRSTUVWXYZ123456';
    $command = (new AtuinHistoryAdapter)->adapt([[
        'id' => 'secret-1',
        'command' => 'GITHUB_TOKEN='.$secret.' curl -H "Authorization: Bearer '.$secret.'" https://user:password@example.test',
        'hostname' => 'workstation',
    ]], 'atuin:secret:1', shellContext())[0];
    $redacted = (new ShellRedactionPolicy)->apply($command);
    $connector = new ShellHistoryConnector(app(ShellHistorySources::class), app(IngestShellHistory::class));
    $installation = shellConnectorInstallation($connector, 'shell:secret');
    $result = app(IngestShellHistory::class)->ingest(
        'shell:workstation/mary/zsh',
        $installation->id,
        [$command],
        new DateTimeImmutable('2026-08-28T11:00:00Z'),
    );
    $stored = DB::table('funes_payloads')->value('contents');
    $payload = json_decode((string) $stored, true, 512, JSON_THROW_ON_ERROR);

    expect($redacted->decision)->toBe(RedactionDecision::Redacted)
        ->and($redacted->reasons)->toContain('credential_assignment', 'bearer_token', 'url_credentials')
        ->and($result->redacted)->toBe(1)
        ->and($stored)->not->toContain($secret)
        ->and($stored)->not->toContain('user:password')
        ->and($payload['redaction']['decision'])->toBe('redacted')
        ->and($payload['redaction']['policy'])->toBe('shell.secrets:1')
        ->and($payload['context']['host'])->toBe('workstation')
        ->and($payload['context']['user'])->toBe('mary');
});

it('replays repeated scans idempotently and records a changed source revision as new history', function (): void {
    $sources = app(ShellHistorySources::class);
    $commands = (new ZshHistoryAdapter)->adapt('git status', 'file:revision:1', shellContext());
    $sources->register(new FixtureShellHistorySource(
        'shell:workstation/mary/zsh',
        new ShellHistoryScan($commands, 'file:revision:1', 'offset:1'),
    ));
    $connector = new ShellHistoryConnector($sources, app(IngestShellHistory::class));
    $installation = shellConnectorInstallation($connector, 'shell:replay');
    $request = new OperationRequest('shell:workstation/mary/zsh', [
        'source_installation_id' => $installation->id,
    ]);
    $first = $connector->syncIncrementally($request);
    $replay = $connector->syncIncrementally($request);
    $afterReplay = DB::table('funes_observations')->count();
    $revised = (new ZshHistoryAdapter)->adapt('git status', 'file:revision:2', shellContext());
    $sources->register(new FixtureShellHistorySource(
        'shell:workstation/mary/zsh',
        new ShellHistoryScan($revised, 'file:revision:2', 'offset:1'),
    ));
    $changedRevision = $connector->syncIncrementally($request);

    expect($first->successful)->toBeTrue()
        ->and($replay->successful)->toBeTrue()
        ->and($first->metadata['cursor'])->toBe('offset:1')
        ->and($first->metadata['accepted_references'])->toBe($replay->metadata['accepted_references'])
        ->and($afterReplay)->toBe(1)
        ->and($changedRevision->successful)->toBeTrue()
        ->and(DB::table('funes_observations')->count())->toBe(2)
        ->and(shellPayloads()[1]['source_revision'])->toBe('file:revision:2');
});

it('isolates identical provider records by host user and source installation', function (): void {
    $connector = new ShellHistoryConnector(app(ShellHistorySources::class), app(IngestShellHistory::class));
    $firstInstallation = shellConnectorInstallation($connector, 'shell:first');
    $secondInstallation = shellConnectorInstallation($connector, 'shell:second');
    $first = (new AtuinHistoryAdapter)->adapt([['id' => 'same-id', 'command' => 'pwd']], 'atuin:1', shellContext('host-a', 'mary'));
    $second = (new AtuinHistoryAdapter)->adapt([['id' => 'same-id', 'command' => 'pwd']], 'atuin:1', shellContext('host-b', 'ada'));
    $ingestor = app(IngestShellHistory::class);
    $ingestor->ingest('shell:host-a/mary/zsh', $firstInstallation->id, $first, new DateTimeImmutable);
    $ingestor->ingest('shell:host-b/ada/zsh', $secondInstallation->id, $second, new DateTimeImmutable);

    expect(DB::table('funes_observations')->count())->toBe(2)
        ->and(array_column(shellPayloads(), 'context'))->toContain(
            ['cwd' => '/workspace', 'environment_reference' => 'environment:dev', 'host' => 'host-a', 'session' => 'session-1', 'shell' => 'zsh', 'user' => 'mary'],
            ['cwd' => '/workspace', 'environment_reference' => 'environment:dev', 'host' => 'host-b', 'session' => 'session-1', 'shell' => 'zsh', 'user' => 'ada'],
        );
});

it('rejects malformed adapter records before acceptance', function (): void {
    expect(fn () => (new AtuinHistoryAdapter)->adapt([['command' => 'pwd']], 'atuin:1', shellContext()))
        ->toThrow(InvalidArgumentException::class, 'id and command')
        ->and(fn () => (new ClaudeBashHistoryAdapter)->adapt([[
            'message_id' => 'message-1',
            'command' => 'pwd',
        ]], 'claude:1', shellContext()))
        ->toThrow(InvalidArgumentException::class, 'tool-use');
});
