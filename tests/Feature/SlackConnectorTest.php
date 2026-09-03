<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Connector\Configuration\ConfigureSource;
use Sifrious\Aleph\Connector\Configuration\SourceConfigurationRequest;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\Slack\AcquireSlackAttachment;
use Sifrious\Aleph\Connector\Slack\ConsumeSlackEvent;
use Sifrious\Aleph\Connector\Slack\ImportSlackActivities;
use Sifrious\Aleph\Connector\Slack\SlackActivity;
use Sifrious\Aleph\Connector\Slack\SlackActivityKind;
use Sifrious\Aleph\Connector\Slack\SlackActivityPage;
use Sifrious\Aleph\Connector\Slack\SlackActivitySource;
use Sifrious\Aleph\Connector\Slack\SlackActivitySources;
use Sifrious\Aleph\Connector\Slack\SlackAttachmentChunk;
use Sifrious\Aleph\Connector\Slack\SlackAttachmentDownloader;
use Sifrious\Aleph\Connector\Slack\SlackAttachmentHandoff;
use Sifrious\Aleph\Connector\Slack\SlackAttachmentReference;
use Sifrious\Aleph\Connector\Slack\SlackCheckpoint;
use Sifrious\Aleph\Connector\Slack\SlackConnector;
use Sifrious\Aleph\Connector\Slack\SlackCredentialBroker;
use Sifrious\Aleph\Connector\Slack\SlackCredentials;
use Sifrious\Aleph\Connector\Slack\SlackEventSecrets;
use Sifrious\Aleph\Connector\Slack\SlackRateLimited;
use Sifrious\Aleph\Connector\Slack\SlackSecretRotation;
use Sifrious\Aleph\Connector\Slack\SlackSecretStore;
use Sifrious\Aleph\Connector\Slack\SlackTokenSecret;
use Sifrious\Aleph\Connector\Slack\SlackWebApiActivitySource;
use Sifrious\Aleph\Connector\Slack\SlackWebApiTransport;
use Sifrious\Aleph\Connector\Values\OperationRequest;
use Sifrious\Aleph\Connector\Values\WebhookDelivery;
use Sifrious\Aleph\Envelope\ObservationMetadata;
use Sifrious\Aleph\Ingestion\Capability;
use Sifrious\Aleph\Ingestion\IngestionCheckpoints;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\SourceStreams;
use Sifrious\Aleph\Testing\Contracts\ConnectorContract;
use Sifrious\Funes\Persistence\ObservationStore;

final class FixtureSlackActivitySource implements SlackActivitySource
{
    /** @var list<array{string, ?string, ?string}> */
    public array $requests = [];

    /** @param array<string, array<string, SlackActivityPage|SlackRateLimited>> $pages */
    public function __construct(private readonly string $reference, private readonly array $pages) {}

    public function sourceReference(): string
    {
        return $this->reference;
    }

    public function page(string $partition, SlackCheckpoint $checkpoint, int $limit): SlackActivityPage
    {
        $this->requests[] = [$partition, $checkpoint->cursor, $checkpoint->highWater];
        $page = $this->pages[$partition][$checkpoint->cursor ?? 'start'] ?? new SlackActivityPage([], null, $checkpoint->highWater, false);
        if ($page instanceof SlackRateLimited) {
            throw $page;
        }

        return $page;
    }
}

final class FixtureSlackDownloader implements SlackAttachmentDownloader
{
    /** @var list<?string> */
    public array $checkpoints = [];

    public function download(SlackAttachmentReference $reference, ?string $checkpoint): SlackAttachmentChunk
    {
        $this->checkpoints[] = $checkpoint;

        return $checkpoint === null ? new SlackAttachmentChunk('part-1', 'offset-6', false) : new SlackAttachmentChunk('part-2', 'offset-12', true);
    }
}

final class FixtureSlackHandoff implements SlackAttachmentHandoff
{
    /** @var array<string, string> */
    public array $accepted = [];

    public function accept(string $historicalReference, string $contents, string $rawReference): string
    {
        return $this->accepted[$historicalReference.'/'.$contents] ??= hash('sha256', $historicalReference.'/'.$contents.'/'.$rawReference);
    }
}

final class FixtureSlackWebApiTransport implements SlackWebApiTransport
{
    /** @var list<array{string, string, array<string, scalar>}> */
    public array $requests = [];

    /** @param array<string, list<array<string, mixed>>> $responses */
    public function __construct(private array $responses) {}

    public function get(string $method, SlackTokenSecret $token, array $query): array
    {
        $this->requests[] = [$method, $token->reveal(), $query];
        $responses = $this->responses[$method] ?? [];
        $response = array_shift($responses);
        $this->responses[$method] = $responses;

        return $response ?? throw new RuntimeException("No fixture response for [{$method}].");
    }
}

final class FixtureSlackWebApiSecretStore implements SlackSecretStore
{
    public function resolve(string $reference): ?SlackTokenSecret
    {
        return $reference === 'secret://slack/live' ? new SlackTokenSecret('xoxb-fixture') : null;
    }

    public function refresh(string $reference): SlackSecretRotation
    {
        throw new RuntimeException('Refresh is outside this fixture.');
    }

    public function revoke(string $reference): void {}
}

function slackActivity(string $workspace, string $id = 'message-1', string $revision = '1700000100.0001', array $relationships = []): SlackActivity
{
    return new SlackActivity(SlackActivityKind::Message, $workspace, $id, $revision, new DateTimeImmutable('@1700000100'), [
        'ts' => '1700000100.0001', 'client_msg_id' => $id, 'user' => 'U1', 'text' => 'hello',
        'files' => [['id' => 'F1', 'name' => 'report.pdf']],
    ], $relationships, 'slack:channel/C1', 'slack:raw/C1/1700000100.0001');
}

function packageSlackConnector(): SlackConnector
{
    return new SlackConnector(app(ImportSlackActivities::class), app(ConsumeSlackEvent::class));
}

function packageSlackInstallation(SlackConnector $connector, string $workspace): object
{
    return app(ConnectorInstallations::class)->create($connector, $workspace, externalAccountId: $workspace, funesSourceAccountId: 'source-account:'.$workspace);
}

function slackOperation(SlackConnector $connector, object $installation, string $workspace, array $partitions, int $version = 0, int $maxPages = 100, ?object $stream = null): array
{
    $stream ??= app(SourceStreams::class)->create($installation->id, $workspace);
    $run = app(IngestionRuns::class)->start($workspace, Capability::IncrementalSync, [], $connector->id(), $installation->id);
    $attempt = app(IngestionRuns::class)->beginAttempt($run);
    $versions = array_fill_keys($partitions, $version);
    $result = $connector->syncIncrementally(new OperationRequest($workspace, ['stream_id' => $stream->id, 'run_id' => $run->id, 'attempt_id' => $attempt->id, 'partitions' => $partitions, 'expected_checkpoint_versions' => $versions, 'max_pages' => $maxPages]));

    return [$result, $stream, $run];
}

it('isolates multiple Slack workspaces through the same package API', function (): void {
    $connector = packageSlackConnector();
    foreach (['slack:workspace/T1', 'slack:workspace/T2'] as $workspace) {
        app(SlackActivitySources::class)->register(new FixtureSlackActivitySource($workspace, ['history:C1' => ['start' => new SlackActivityPage([slackActivity($workspace)], null, '1700000100.0001', false)]]));
        slackOperation($connector, packageSlackInstallation($connector, $workspace), $workspace, ['history:C1']);
    }
    expect(DB::table('funes_observations')->count())->toBe(2)
        ->and(DB::table('funes_sources')->pluck('reference')->all())->toContain('slack:workspace/T1', 'slack:workspace/T2');
});

it('pauses and resumes channel history from accepted cursor and high water without duplication', function (): void {
    $workspace = 'slack:workspace/T1';
    $activity = slackActivity($workspace);
    $source = new FixtureSlackActivitySource($workspace, ['history:C1' => [
        'start' => new SlackActivityPage([$activity], 'cursor-1', '1700000100.0001', true),
        'cursor-1' => new SlackActivityPage([$activity], null, '1700000200.0001', false),
    ]]);
    app(SlackActivitySources::class)->register($source);
    $connector = packageSlackConnector();
    $installation = packageSlackInstallation($connector, $workspace);
    [$partial, $stream] = slackOperation($connector, $installation, $workspace, ['history:C1'], maxPages: 1);
    [$complete] = slackOperation($connector, $installation, $workspace, ['history:C1'], 1, stream: $stream);
    $checkpoint = SlackCheckpoint::decode(app(IngestionCheckpoints::class)->latest($stream, Capability::IncrementalSync, 'history:C1')?->value->value);
    expect($partial->complete)->toBeFalse()->and($complete->complete)->toBeTrue()
        ->and($source->requests)->toBe([['history:C1', null, null], ['history:C1', 'cursor-1', '1700000100.0001']])
        ->and($checkpoint->cursor)->toBeNull()->and($checkpoint->highWater)->toBe('1700000200.0001')
        ->and(DB::table('funes_observations')->count())->toBe(1);
});

it('preserves message thread file relationships and complete provenance in Funes', function (): void {
    $workspace = 'slack:workspace/T1';
    $activity = slackActivity($workspace, relationships: ['thread' => 'slack:message/C1/1700000000.0001', 'file:F1' => 'slack:file/F1']);
    app(SlackActivitySources::class)->register(new FixtureSlackActivitySource($workspace, ['history:C1' => ['start' => new SlackActivityPage([$activity], null, '1700000100.0001', false)]]));
    $connector = packageSlackConnector();
    [, , $run] = slackOperation($connector, packageSlackInstallation($connector, $workspace), $workspace, ['history:C1']);
    $observation = app(ObservationStore::class)->get((string) DB::table('funes_observations')->value('id'));
    $metadata = $observation === null ? [] : ObservationMetadata::extension($observation, 'slack.activity');
    $payload = json_decode((string) DB::table('funes_payloads')->value('contents'), true, 512, JSON_THROW_ON_ERROR);
    expect($payload['workspace_reference'])->toBe($workspace)->and($payload['channel_reference'])->toBe('slack:channel/C1')
        ->and($payload['provider_id'])->toBe('message-1')->and($payload['occurred_at'])->not->toBeNull()
        ->and($metadata['relationships']['thread'])->toBe('slack:message/C1/1700000000.0001')
        ->and($metadata['relationships']['file:F1'])->toBe('slack:file/F1')
        ->and(DB::table('aleph_ingestion_runs')->where('id', $run->id)->exists())->toBeTrue();
});

it('converges verified Events API and polling overlap on one history effect', function (): void {
    $workspace = 'slack:workspace/T1';
    app(SlackActivitySources::class)->register(new FixtureSlackActivitySource($workspace, ['history:C1' => ['start' => new SlackActivityPage([slackActivity($workspace)], null, '1700000100.0001', false)]]));
    $connector = packageSlackConnector();
    $installation = packageSlackInstallation($connector, $workspace);
    slackOperation($connector, $installation, $workspace, ['history:C1']);
    $body = json_encode(['event_id' => 'Ev1', 'event' => ['type' => 'message', 'ts' => '1700000100.0001', 'event_ts' => '1700000100.0001', 'client_msg_id' => 'message-1', 'channel' => 'C1', 'user' => 'U1', 'text' => 'hello', 'files' => [['id' => 'F1', 'name' => 'report.pdf']]]], JSON_THROW_ON_ERROR);
    $secret = 'signing-secret';
    $timestamp = '1700000100';
    app(SlackEventSecrets::class)->register($installation->id, $secret);
    $signature = 'v0='.hash_hmac('sha256', 'v0:'.$timestamp.':'.$body, $secret);
    $event = $connector->consumeWebhook(new WebhookDelivery($installation->id, ['X-Slack-Request-Timestamp' => $timestamp, 'X-Slack-Signature' => $signature], $body));
    expect($event->successful)->toBeTrue()->and(DB::table('funes_observations')->count())->toBe(1);
});

it('returns retryable rate limits without advancing an unaccepted checkpoint', function (): void {
    $workspace = 'slack:workspace/T1';
    app(SlackActivitySources::class)->register(new FixtureSlackActivitySource($workspace, ['history:C1' => ['start' => new SlackRateLimited(new DateTimeImmutable('2026-08-28T13:00:00+00:00'))]]));
    $connector = packageSlackConnector();
    [$result, $stream] = slackOperation($connector, packageSlackInstallation($connector, $workspace), $workspace, ['history:C1']);
    expect($result->successful)->toBeFalse()->and($result->metadata['retryable'])->toBeTrue()
        ->and(app(IngestionCheckpoints::class)->latest($stream, Capability::IncrementalSync, 'history:C1'))->toBeNull();
});

it('resumes attachment acquisition with stable provenance and idempotent handoff effects', function (): void {
    $downloader = new FixtureSlackDownloader;
    $handoff = new FixtureSlackHandoff;
    $acquirer = new AcquireSlackAttachment($downloader, $handoff);
    $reference = new SlackAttachmentReference('T1', 'C1', '1700000100.0001', 'F1', 'slack:file/F1');
    $partial = $acquirer->acquire($reference, maxChunks: 1);
    $complete = $acquirer->acquire($reference, $partial->checkpoint, 1);
    $replay = $acquirer->acquire($reference, $partial->checkpoint, 1);
    expect($partial->complete)->toBeFalse()->and($complete->complete)->toBeTrue()
        ->and($downloader->checkpoints)->toBe([null, 'offset-6', 'offset-6'])
        ->and($complete->handoffReferences)->toBe($replay->handoffReferences)
        ->and($reference->historicalReference())->toContain('digory:slack-attachment/T1/C1/1700000100.0001/F1');
});

it('exposes host-neutral behavior without Landing jobs models controllers or UI', function (): void {
    $serialized = serialize([new SlackCheckpoint('cursor', 'high-water'), slackActivity('slack:workspace/T1')]);
    expect($serialized)->not->toContain('App\\', 'SyncSlackChannelHistory', 'SlackMessage', 'Illuminate\\Http\\Client');
});

it('retrieves recorded Slack Web API pages through an opaque host credential', function (): void {
    $workspace = 'slack:workspace/T1';
    $connector = packageSlackConnector();
    $installation = app(ConnectorInstallations::class)->create(
        $connector,
        'Slack live fixture',
        credentialsReference: 'secret://slack/live',
        externalAccountId: $workspace,
    );
    $transport = new FixtureSlackWebApiTransport([
        'conversations.history' => [[
            'ok' => true,
            'messages' => [[
                'type' => 'message',
                'ts' => '1700000100.0001',
                'client_msg_id' => 'message-live-1',
                'user' => 'U1',
                'text' => 'recorded fixture',
                'files' => [['id' => 'F1', 'name' => 'report.pdf']],
            ]],
            'response_metadata' => ['next_cursor' => 'cursor-2'],
        ], [
            'ok' => true,
            'messages' => [[
                'type' => 'message',
                'ts' => '1700000200.0001',
                'client_msg_id' => 'message-live-2',
                'user' => 'U1',
                'text' => 'second recorded fixture page',
            ]],
            'response_metadata' => ['next_cursor' => ''],
        ]],
    ]);
    $broker = new SlackCredentialBroker(
        app(SlackCredentials::class),
        new FixtureSlackWebApiSecretStore,
        app(ConnectorInstallations::class),
    );
    $source = new SlackWebApiActivitySource($workspace, $installation->id, $broker, $transport);
    app(SlackActivitySources::class)->register($source);

    [$partial, $stream] = slackOperation($connector, $installation, $workspace, ['history:C1'], maxPages: 1);
    [$complete] = slackOperation($connector, $installation, $workspace, ['history:C1'], 1, stream: $stream);
    $checkpoint = SlackCheckpoint::decode(
        app(IngestionCheckpoints::class)->latest($stream, Capability::IncrementalSync, 'history:C1')?->value->value,
    );

    expect($partial->complete)->toBeFalse()
        ->and($complete->complete)->toBeTrue()
        ->and($checkpoint->cursor)->toBeNull()
        ->and($checkpoint->highWater)->toBe('1700000200.0001')
        ->and(DB::table('funes_observations')->count())->toBe(2)
        ->and($transport->requests)->toHaveCount(2)
        ->and($transport->requests[0][0])->toBe('conversations.history')
        ->and($transport->requests[0][1])->toBe('xoxb-fixture')
        ->and($transport->requests[1][2]['cursor'] ?? null)->toBe('cursor-2')
        ->and($transport->requests[1][2]['oldest'] ?? null)->toBe('1700000100.0001');
});

it('configures the shipped Slack connector with an opaque token reference', function (): void {
    $connector = app(SlackConnector::class);
    app(ConnectorRegistry::class)->register($connector);

    $configured = app(ConfigureSource::class)->configure('slack', new SourceConfigurationRequest(
        sourceKey: 'workspace-t1',
        name: 'Workspace T1',
        values: [
            'workspace' => 'T1',
            'channels' => ['C1'],
            'history_days' => 30,
        ],
        credentialReference: 'secret://slack/live',
    ));

    expect($configured->sourceReference())->toBe('slack:workspace-t1')
        ->and($configured->installation->credentialsReference)->toBe('secret://slack/live')
        ->and($configured->installation->configuration['channels'])->toBe(['C1'])
        ->and(DB::table('funes_observations')->count())->toBe(1)
        ->and(ConnectorContract::violations($connector))->toBe([])
        ->and(ConnectorContract::probeAll($connector))->toBe([]);
});
