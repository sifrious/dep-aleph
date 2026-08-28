<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Connector\Communication\CommunicationPage;
use Sifrious\Aleph\Connector\Communication\CommunicationProvider;
use Sifrious\Aleph\Connector\Communication\CommunicationSource;
use Sifrious\Aleph\Connector\Communication\CommunicationSources;
use Sifrious\Aleph\Connector\Communication\DiscordMessageAdapter;
use Sifrious\Aleph\Connector\Communication\ImportCommunicationRecords;
use Sifrious\Aleph\Connector\Communication\ProviderCommunicationConnector;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\Values\OperationRequest;
use Sifrious\Aleph\Ingestion\Capability;
use Sifrious\Aleph\Ingestion\IngestionCheckpoints;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\SourceStreams;

final class FixtureDiscordSource implements CommunicationSource
{
    /** @var list<?string> */
    public array $requests = [];

    /** @param array<string, CommunicationPage> $pages */
    public function __construct(
        private readonly string $reference,
        private readonly array $pages,
        public readonly string $token = 'discord-bot-secret',
    ) {}

    public function sourceReference(): string
    {
        return $this->reference;
    }

    public function provider(): CommunicationProvider
    {
        return CommunicationProvider::Discord;
    }

    public function checkpointType(): string
    {
        return 'discord.gateway-sequence';
    }

    public function page(?string $checkpoint, int $limit): CommunicationPage
    {
        $this->requests[] = $checkpoint;

        return $this->pages[$checkpoint ?? 'start'] ?? new CommunicationPage([], $checkpoint, false);
    }
}

function discordEvent(string $eventId = 'event-101', string $type = 'MESSAGE_CREATE', array $overrides = []): array
{
    return array_replace_recursive([
        'event_id' => $eventId,
        'type' => $type,
        'observed_at' => '2026-08-28T10:00:01Z',
        'data' => [
            'id' => 'message-1',
            'guild_id' => 'guild-1',
            'channel_id' => 'channel-1',
            'timestamp' => '2026-08-28T10:00:00Z',
            'content' => 'Hello Discord',
            'author' => ['id' => 'user-1', 'username' => 'alice', 'bot' => false],
        ],
        'bot_token' => 'discord-bot-secret',
    ], $overrides);
}

function discordConnector(): ProviderCommunicationConnector
{
    return new ProviderCommunicationConnector(CommunicationProvider::Discord, app(ImportCommunicationRecords::class));
}

function discordOperation(ProviderCommunicationConnector $connector, object $installation, string $source, int $version = 0, int $maxPages = 100, ?object $stream = null): array
{
    $stream ??= app(SourceStreams::class)->create($installation->id, $source);
    $run = app(IngestionRuns::class)->start($source, Capability::IncrementalSync, [], $connector->id(), $installation->id);
    $attempt = app(IngestionRuns::class)->beginAttempt($run);
    $result = $connector->syncIncrementally(new OperationRequest($source, [
        'stream_id' => $stream->id,
        'run_id' => $run->id,
        'attempt_id' => $attempt->id,
        'expected_checkpoint_version' => $version,
        'page_size' => 50,
        'max_pages' => $maxPages,
    ]));

    return [$result, $stream];
}

it('normalizes Discord server thread reply edit delete reaction bot webhook and attachment fixtures', function (): void {
    $adapter = new DiscordMessageAdapter;
    $server = $adapter->adapt(discordEvent(), 'discord:guild/1', 'discord:event/101');
    $thread = $adapter->adapt(discordEvent('event-102', 'MESSAGE_CREATE', ['data' => [
        'id' => 'message-2',
        'thread_id' => 'thread-1',
        'message_reference' => ['message_id' => 'message-1'],
        'reactions' => [['emoji' => ['name' => '👍'], 'count' => 2, 'participant_ids' => ['user-1', 'user-2']]],
        'attachments' => [['id' => 'attachment-1', 'filename' => 'diagram.png', 'content_type' => 'image/png', 'size' => 84]],
        'embeds' => [['type' => 'rich', 'title' => 'Build']],
    ]]), 'discord:guild/1', 'discord:event/102');
    $edited = $adapter->adapt(discordEvent('event-103', 'MESSAGE_UPDATE', ['data' => ['edited_timestamp' => '2026-08-28T10:01:00Z']]), 'discord:guild/1', 'discord:event/103');
    $deleted = $adapter->adapt(discordEvent('event-104', 'MESSAGE_DELETE'), 'discord:guild/1', 'discord:event/104');
    $bot = $adapter->adapt(discordEvent('event-105', 'MESSAGE_CREATE', ['data' => ['author' => ['id' => 'bot-1', 'username' => 'helper', 'bot' => true]]]), 'discord:guild/1', 'discord:event/105');
    $webhook = $adapter->adapt(discordEvent('event-106', 'MESSAGE_CREATE', ['data' => ['webhook_id' => 'webhook-1', 'author' => ['id' => 'webhook-1', 'username' => 'deploy']]]), 'discord:guild/1', 'discord:event/106');
    $unavailable = $adapter->adapt(discordEvent('event-107', 'CHANNEL_DELETE', ['data' => ['id' => 'channel-2', 'channel_id' => 'channel-2']]), 'discord:guild/1', 'discord:event/107');

    expect($server->reconciliation)->toMatchArray(['guild_id' => 'guild-1', 'channel_id' => 'channel-1'])
        ->and($thread->conversationId)->toBe('thread-1')
        ->and($thread->replyTo)->toBe('message-1')
        ->and($thread->reactions[0]->value)->toBe('👍')
        ->and($thread->attachments[0]->digoryReference)->toBe('digory:discord-attachment/message-2/attachment-1')
        ->and($thread->reconciliation['embeds'][0]['title'])->toBe('Build')
        ->and($edited->change->value)->toBe('edited')
        ->and($deleted->change->value)->toBe('deleted')
        ->and($bot->participants[0]->kind)->toBe('bot')
        ->and($webhook->participants[0]->kind)->toBe('webhook')
        ->and($unavailable->change->value)->toBe('unavailable')
        ->and(json_encode(array_map(static fn ($record): array => $record->toArray(), [$server, $thread, $edited, $deleted, $bot, $webhook, $unavailable]), JSON_THROW_ON_ERROR))
        ->not->toContain('discord-bot-secret', 'bot_token', 'Discord\\');
});

it('converges replayed Discord events and resumes from an accepted gateway checkpoint', function (): void {
    $adapter = new DiscordMessageAdapter;
    $sourceReference = 'discord:guild/1';
    $record = $adapter->adapt(discordEvent(), $sourceReference, 'discord:event/101');
    $source = new FixtureDiscordSource($sourceReference, [
        'start' => new CommunicationPage([$record, $record], '101', true),
        '101' => new CommunicationPage([$adapter->adapt(discordEvent('event-102', 'MESSAGE_CREATE', ['data' => ['id' => 'message-2']]), $sourceReference, 'discord:event/102')], '102', false),
    ]);
    app(CommunicationSources::class)->register($source);
    $connector = discordConnector();
    $installation = app(ConnectorInstallations::class)->create($connector, $sourceReference, externalAccountId: 'guild-1', funesSourceAccountId: 'source-account:discord-1');
    [$partial, $stream] = discordOperation($connector, $installation, $sourceReference, maxPages: 1);
    [$complete] = discordOperation($connector, $installation, $sourceReference, 1, stream: $stream);
    $checkpoint = app(IngestionCheckpoints::class)->latest($stream, Capability::IncrementalSync, 'conversation');

    expect($partial->complete)->toBeFalse()
        ->and($partial->records)->toBe(1)
        ->and($complete->complete)->toBeTrue()
        ->and($source->requests)->toBe([null, '101'])
        ->and($checkpoint?->value->format)->toBe('discord.gateway-sequence')
        ->and($checkpoint?->value->value)->toBe('102')
        ->and(DB::table('funes_observations')->count())->toBe(2);
});

it('keeps Discord credentials outside persistence results and Funes', function (): void {
    $sourceReference = 'discord:guild/1';
    $record = (new DiscordMessageAdapter)->adapt(discordEvent(), $sourceReference, 'discord:event/101');
    app(CommunicationSources::class)->register(new FixtureDiscordSource($sourceReference, [
        'start' => new CommunicationPage([$record], '101', false),
    ]));
    $connector = discordConnector();
    $installation = app(ConnectorInstallations::class)->create($connector, $sourceReference, externalAccountId: 'guild-1', funesSourceAccountId: 'source-account:discord-1');
    [$result] = discordOperation($connector, $installation, $sourceReference);
    $ordinaryPersistence = json_encode([
        DB::table('funes_payloads')->get()->all(),
        DB::table('funes_observations')->get()->all(),
        DB::table('aleph_ingestion_runs')->get()->all(),
        DB::table('aleph_ingestion_attempts')->get()->all(),
        $result->toArray(),
    ], JSON_THROW_ON_ERROR);

    expect($ordinaryPersistence)->not->toContain('discord-bot-secret', 'bot_token', 'authorization');
});
