<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\Conversation\AiConversationConnector;
use Sifrious\Aleph\Connector\Conversation\AiConversationQueries;
use Sifrious\Aleph\Connector\Conversation\AiConversationScan;
use Sifrious\Aleph\Connector\Conversation\AiConversationSource;
use Sifrious\Aleph\Connector\Conversation\AiConversationSources;
use Sifrious\Aleph\Connector\Conversation\AiMessageRole;
use Sifrious\Aleph\Connector\Conversation\AlternateConversationAdapter;
use Sifrious\Aleph\Connector\Conversation\ClaudeConversationAdapter;
use Sifrious\Aleph\Connector\Conversation\CodexConversationAdapter;
use Sifrious\Aleph\Connector\Conversation\IngestAiConversations;
use Sifrious\Aleph\Connector\Values\OperationRequest;

final class FixtureAiConversationSource implements AiConversationSource
{
    public function __construct(
        private readonly string $reference,
        private readonly AiConversationScan $scan,
    ) {}

    public function sourceReference(): string
    {
        return $this->reference;
    }

    public function scan(?string $cursor): AiConversationScan
    {
        return $this->scan;
    }
}

function claudeConversationFixture(): string
{
    $records = [
        [
            'type' => 'user',
            'sessionId' => 'claude-session-1',
            'uuid' => 'claude-user-1',
            'parentUuid' => null,
            'timestamp' => '2026-08-28T09:00:00Z',
            'cwd' => '/workspace/landing',
            'gitBranch' => 'main',
            'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Inspect the tests.']]],
        ],
        [
            'type' => 'assistant',
            'sessionId' => 'claude-session-1',
            'uuid' => 'claude-tool-1',
            'parentUuid' => 'claude-user-1',
            'timestamp' => '2026-08-28T09:00:01Z',
            'isSidechain' => true,
            'agentId' => 'agent-1',
            'message' => ['role' => 'assistant', 'content' => [
                ['type' => 'thinking', 'thinking' => 'Find the narrowest test.'],
                ['type' => 'tool_use', 'id' => 'tool-call-1', 'name' => 'Read', 'input' => ['path' => 'tests/Feature']],
            ]],
        ],
        [
            'type' => 'user',
            'sessionId' => 'claude-session-1',
            'uuid' => 'claude-result-1',
            'parentUuid' => 'claude-tool-1',
            'timestamp' => '2026-08-28T09:00:02Z',
            'message' => ['role' => 'user', 'content' => [[
                'type' => 'tool_result',
                'tool_use_id' => 'tool-call-1',
                'content' => [['type' => 'text', 'text' => 'AiConversationConnectorTest.php']],
            ]]],
        ],
    ];

    return implode("\n", array_map(static fn (array $record): string => json_encode($record, JSON_THROW_ON_ERROR), $records));
}

function codexConversationFixture(): string
{
    $records = [
        ['timestamp' => '2026-08-28T08:59:59Z', 'type' => 'session_meta', 'payload' => ['id' => 'codex-session-1', 'cwd' => '/workspace/aleph', 'cli_version' => '1.2.3']],
        ['timestamp' => '2026-08-28T09:00:03Z', 'type' => 'response_item', 'payload' => ['type' => 'message', 'id' => 'codex-user-1', 'role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Run the focused test.']]]],
        ['timestamp' => '2026-08-28T09:00:04Z', 'type' => 'response_item', 'payload' => ['type' => 'function_call', 'call_id' => 'codex-call-1', 'name' => 'exec_command', 'arguments' => '{"cmd":"composer test"}']],
        ['timestamp' => '2026-08-28T09:00:05Z', 'type' => 'response_item', 'payload' => ['type' => 'function_call_output', 'call_id' => 'codex-call-1-result', 'output' => 'PASS']],
    ];

    return implode("\n", array_map(static fn (array $record): string => json_encode($record, JSON_THROW_ON_ERROR), $records));
}

function aiConversationPayloads(): array
{
    return DB::table('funes_payloads')->orderBy('id')->pluck('contents')->map(
        static fn (string $contents): array => json_decode($contents, true, 512, JSON_THROW_ON_ERROR),
    )->all();
}

function aiConversationInstallation(AiConversationConnector $connector, string $account = 'ai:local'): object
{
    return app(ConnectorInstallations::class)->create(
        $connector,
        $account,
        externalAccountId: $account,
        funesSourceAccountId: 'source-account:'.$account,
    );
}

it('characterizes Claude chronology branches sidechains tools and message parts', function (): void {
    $conversation = (new ClaudeConversationAdapter)->adapt(
        claudeConversationFixture(),
        'claude:file:revision-1',
        'file:///history/claude-session-1.jsonl',
    )[0];
    $thread = (new AiConversationQueries)->thread($conversation, 'claude-result-1');

    expect($conversation->providerId)->toBe('claude-session-1')
        ->and($conversation->providerMetadata['cwd'])->toBe('/workspace/landing')
        ->and($conversation->messages)->toHaveCount(3)
        ->and($conversation->messages[1]->role)->toBe(AiMessageRole::ToolUse)
        ->and($conversation->messages[1]->sidechain)->toBeTrue()
        ->and($conversation->messages[1]->branchId)->toBeNull()
        ->and($conversation->messages[1]->parts[0]->type)->toBe('thinking')
        ->and($conversation->messages[1]->parts[1]->providerBlock['input']['path'])->toBe('tests/Feature')
        ->and($conversation->messages[2]->role)->toBe(AiMessageRole::ToolResult)
        ->and($conversation->messages[2]->parts[0]->text)->toBe('AiConversationConnectorTest.php')
        ->and(array_column($thread, 'providerId'))->toBe(['claude-user-1', 'claude-tool-1', 'claude-result-1']);
});

it('normalizes Codex and alternate provider fixtures through the same queries', function (): void {
    $codex = (new CodexConversationAdapter)->adapt(
        codexConversationFixture(),
        'codex:file:revision-1',
        'file:///history/codex-session-1.jsonl',
    )[0];
    $alternate = (new AlternateConversationAdapter)->adapt([
        'id' => 'alternate-session-1',
        'metadata' => ['model' => 'other-model'],
        'messages' => [[
            'id' => 'alternate-assistant-1',
            'role' => 'assistant',
            'author' => 'assistant',
            'timestamp' => '2026-08-28T09:00:06Z',
            'thread_id' => 'thread-1',
            'branch_id' => 'branch-a',
            'blocks' => [['type' => 'text', 'text' => 'Focused test passed.', 'provider_annotation' => ['confidence' => 0.9]]],
        ]],
    ], 'alternate:page:1', 'alternate://session/1')[0];
    $chronology = (new AiConversationQueries)->chronology([$alternate, $codex]);
    $assistant = (new AiConversationQueries)->chronology([$alternate, $codex], 'assistant');

    expect(array_column($chronology, 'providerId'))->toBe([
        'codex-user-1',
        'codex-call-1',
        'codex-call-1-result',
        'alternate-assistant-1',
    ])->and($codex->messages[1]->role)->toBe(AiMessageRole::ToolUse)
        ->and($codex->messages[2]->role)->toBe(AiMessageRole::ToolResult)
        ->and($alternate->messages[0]->branchId)->toBe('branch-a')
        ->and($alternate->messages[0]->threadId)->toBe('thread-1')
        ->and($alternate->messages[0]->parts[0]->providerBlock['provider_annotation']['confidence'])->toBe(0.9)
        ->and(array_column($assistant, 'providerId'))->toBe(['codex-call-1', 'alternate-assistant-1']);
});

it('round trips provider records parts and raw references through Funes', function (): void {
    $conversations = (new ClaudeConversationAdapter)->adapt(
        claudeConversationFixture(),
        'claude:file:revision-1',
        'file:///history/claude-session-1.jsonl',
    );
    $connector = new AiConversationConnector(app(AiConversationSources::class), app(IngestAiConversations::class));
    $installation = aiConversationInstallation($connector);
    $result = app(IngestAiConversations::class)->ingest(
        'ai:claude:local',
        $installation->id,
        $conversations,
        new DateTimeImmutable('2026-08-28T10:00:00Z'),
    );
    $payloads = aiConversationPayloads();
    $tool = $payloads[1];

    expect($result->conversations)->toBe(1)
        ->and($result->messages)->toBe(3)
        ->and($tool['conversation']['raw_reference'])->toBe('file:///history/claude-session-1.jsonl')
        ->and($tool['message']['raw_reference'])->toBe('file:///history/claude-session-1.jsonl#L2')
        ->and($tool['message']['provider_record']['uuid'])->toBe('claude-tool-1')
        ->and($tool['message']['parts'][1]['provider_block']['input']['path'])->toBe('tests/Feature')
        ->and($tool['message']['sidechain'])->toBeTrue()
        ->and($tool['message']['parent_provider_id'])->toBe('claude-user-1');
});

it('replays duplicate scans idempotently and accepts a changed source revision', function (): void {
    $sources = app(AiConversationSources::class);
    $conversations = (new CodexConversationAdapter)->adapt(
        codexConversationFixture(),
        'codex:file:revision-1',
        'file:///history/codex-session-1.jsonl',
    );
    $sources->register(new FixtureAiConversationSource(
        'ai:codex:local',
        new AiConversationScan($conversations, 'codex:file:revision-1', 'offset:4'),
    ));
    $connector = new AiConversationConnector($sources, app(IngestAiConversations::class));
    $installation = aiConversationInstallation($connector, 'ai:codex');
    $request = new OperationRequest('ai:codex:local', ['source_installation_id' => $installation->id]);
    $first = $connector->syncIncrementally($request);
    $replay = $connector->syncIncrementally($request);
    $afterReplay = DB::table('funes_observations')->count();
    $revised = (new CodexConversationAdapter)->adapt(
        codexConversationFixture(),
        'codex:file:revision-2',
        'file:///history/codex-session-1.jsonl',
    );
    $sources->register(new FixtureAiConversationSource(
        'ai:codex:local',
        new AiConversationScan($revised, 'codex:file:revision-2', 'offset:4'),
    ));
    $changed = $connector->syncIncrementally($request);

    expect($first->successful)->toBeTrue()
        ->and($replay->successful)->toBeTrue()
        ->and($first->metadata['cursor'])->toBe('offset:4')
        ->and($first->metadata['accepted_references'])->toBe($replay->metadata['accepted_references'])
        ->and($afterReplay)->toBe(3)
        ->and($changed->successful)->toBeTrue()
        ->and(DB::table('funes_observations')->count())->toBe(6);
});

it('rejects malformed alternate and Codex fixtures before acceptance', function (): void {
    expect(fn () => (new AlternateConversationAdapter)->adapt(['messages' => []], 'revision:1', 'alternate://bad'))
        ->toThrow(InvalidArgumentException::class, 'id and messages')
        ->and(fn () => (new CodexConversationAdapter)->adapt(
            json_encode(['type' => 'response_item', 'payload' => ['type' => 'message']], JSON_THROW_ON_ERROR),
            'revision:1',
            'file:///bad.jsonl',
        ))->toThrow(InvalidArgumentException::class, 'session_meta');
});
