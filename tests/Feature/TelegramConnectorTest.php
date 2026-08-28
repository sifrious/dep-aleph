<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Connector\Communication\CommunicationPage;
use Sifrious\Aleph\Connector\Communication\CommunicationProvider;
use Sifrious\Aleph\Connector\Communication\CommunicationSource;
use Sifrious\Aleph\Connector\Communication\CommunicationSources;
use Sifrious\Aleph\Connector\Communication\F28CommunicationRecord;
use Sifrious\Aleph\Connector\Communication\ImportCommunicationRecords;
use Sifrious\Aleph\Connector\Communication\ProviderCommunicationConnector;
use Sifrious\Aleph\Connector\Communication\TelegramMessageAdapter;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\Values\OperationRequest;
use Sifrious\Aleph\Ingestion\Capability;
use Sifrious\Aleph\Ingestion\IngestionCheckpoints;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\RunStatus;
use Sifrious\Aleph\Ingestion\SourceStreams;

final class FixtureTelegramSource implements CommunicationSource
{
    /** @var list<?string> */
    public array $requests = [];

    /** @param array<string, CommunicationPage> $pages */
    public function __construct(
        private readonly string $reference,
        private readonly array $pages,
        public readonly string $token = 'telegram-bot-secret',
    ) {}

    public function sourceReference(): string
    {
        return $this->reference;
    }

    public function provider(): CommunicationProvider
    {
        return CommunicationProvider::Telegram;
    }

    public function checkpointType(): string
    {
        return 'telegram.update-id';
    }

    public function page(?string $checkpoint, int $limit): CommunicationPage
    {
        $this->requests[] = $checkpoint;

        return $this->pages[$checkpoint ?? 'start'] ?? new CommunicationPage([], $checkpoint, false);
    }
}

function telegramUpdate(int $updateId = 101, array $overrides = []): array
{
    return array_replace_recursive([
        'update_id' => $updateId,
        'message' => [
            'message_id' => 7,
            'date' => 1787911200,
            'chat' => ['id' => 42, 'type' => 'private', 'username' => 'alice'],
            'from' => ['id' => 9, 'first_name' => 'Alice', 'is_bot' => false],
            'text' => 'Hello from Telegram',
        ],
        'bot_token' => 'telegram-bot-secret',
    ], $overrides);
}

function telegramConnector(): ProviderCommunicationConnector
{
    return new ProviderCommunicationConnector(CommunicationProvider::Telegram, app(ImportCommunicationRecords::class));
}

function telegramOperation(ProviderCommunicationConnector $connector, object $installation, string $source, Capability $capability, int $version = 0, int $maxPages = 100, ?object $stream = null): array
{
    $stream ??= app(SourceStreams::class)->create($installation->id, $source);
    $run = app(IngestionRuns::class)->start($source, $capability, [], $connector->id(), $installation->id);
    $attempt = app(IngestionRuns::class)->beginAttempt($run);
    $request = new OperationRequest($source, [
        'stream_id' => $stream->id,
        'run_id' => $run->id,
        'attempt_id' => $attempt->id,
        'expected_checkpoint_version' => $version,
        'page_size' => 50,
        'max_pages' => $maxPages,
    ]);
    $result = $capability === Capability::Backfill ? $connector->backfill($request) : $connector->syncIncrementally($request);

    return [$result, $stream, $run];
}

it('normalizes direct group edited forwarded media and unsupported Telegram fixtures into F28', function (): void {
    $adapter = new TelegramMessageAdapter;
    $records = [
        $adapter->adapt(telegramUpdate(), 'telegram:account/1', 'telegram:update/101'),
        $adapter->adapt(telegramUpdate(102, ['message' => ['chat' => ['id' => -100, 'type' => 'supergroup', 'title' => 'Builders']]]), 'telegram:account/1', 'telegram:update/102'),
        $adapter->adapt(['update_id' => 103, 'edited_message' => telegramUpdate()['message'] + ['edit_date' => 1787911300]], 'telegram:account/1', 'telegram:update/103'),
        $adapter->adapt(telegramUpdate(104, ['message' => ['forward_from' => ['id' => 33], 'document' => ['file_id' => 'file-1', 'file_name' => 'notes.pdf', 'mime_type' => 'application/pdf', 'file_size' => 81]]]), 'telegram:account/1', 'telegram:update/104'),
        $adapter->adapt(['update_id' => 105, 'poll_answer' => ['poll_id' => 'poll-1']], 'telegram:account/1', 'telegram:update/105'),
    ];

    foreach ($records as $record) {
        expect($record)->toBeInstanceOf(F28CommunicationRecord::class)
            ->and($record->toArray()['contract'])->toBe('F28')
            ->and(json_encode($record->toArray(), JSON_THROW_ON_ERROR))->not->toContain('telegram-bot-secret', 'bot_token', 'Telegram\\Bot');
    }

    expect($records[0]->conversationKind)->toBe('private')
        ->and($records[1]->conversationKind)->toBe('supergroup')
        ->and($records[2]->change->value)->toBe('edited')
        ->and($records[2]->editedAt)->not->toBeNull()
        ->and($records[3]->forwardedFrom)->toBe('33')
        ->and($records[3]->attachments[0]->digoryReference)->toBe('digory:telegram-attachment/42/7/file-1')
        ->and($records[4]->change->value)->toBe('unsupported')
        ->and($records[4]->reconciliation['provider_event'])->toBe('unsupported');
});

it('pauses Telegram backfill at an accepted checkpoint and resumes without duplicate history', function (): void {
    $adapter = new TelegramMessageAdapter;
    $sourceReference = 'telegram:account/1';
    $record = $adapter->adapt(telegramUpdate(), $sourceReference, 'telegram:update/101');
    $source = new FixtureTelegramSource($sourceReference, [
        'start' => new CommunicationPage([$record, $record], '101', true),
        '101' => new CommunicationPage([$adapter->adapt(telegramUpdate(102), $sourceReference, 'telegram:update/102')], '102', false),
    ]);
    app(CommunicationSources::class)->register($source);
    $connector = telegramConnector();
    $installation = app(ConnectorInstallations::class)->create($connector, $sourceReference, externalAccountId: 'telegram-account-1', funesSourceAccountId: 'source-account:telegram-1');
    [$partial, $stream, $pausedRun] = telegramOperation($connector, $installation, $sourceReference, Capability::Backfill, maxPages: 1);
    [$complete] = telegramOperation($connector, $installation, $sourceReference, Capability::Backfill, 1, stream: $stream);
    $checkpoint = app(IngestionCheckpoints::class)->latest($stream, Capability::Backfill, 'conversation');

    expect($partial->successful)->toBeTrue()
        ->and($partial->complete)->toBeFalse()
        ->and($partial->records)->toBe(1)
        ->and($complete->complete)->toBeTrue()
        ->and($source->requests)->toBe([null, '101'])
        ->and($checkpoint?->value->format)->toBe('telegram.update-id')
        ->and($checkpoint?->value->value)->toBe('102')
        ->and($checkpoint?->version)->toBe(2)
        ->and(app(IngestionRuns::class)->find($pausedRun->id)?->status)->toBe(RunStatus::Partial)
        ->and(DB::table('funes_observations')->count())->toBe(2);
});

it('keeps Telegram credentials outside persistence results and Funes', function (): void {
    $sourceReference = 'telegram:account/1';
    $record = (new TelegramMessageAdapter)->adapt(telegramUpdate(), $sourceReference, 'telegram:update/101');
    app(CommunicationSources::class)->register(new FixtureTelegramSource($sourceReference, [
        'start' => new CommunicationPage([$record], '101', false),
    ]));
    $connector = telegramConnector();
    $installation = app(ConnectorInstallations::class)->create($connector, $sourceReference, externalAccountId: 'telegram-account-1', funesSourceAccountId: 'source-account:telegram-1');
    [$result] = telegramOperation($connector, $installation, $sourceReference, Capability::IncrementalSync);
    $ordinaryPersistence = json_encode([
        DB::table('funes_payloads')->get()->all(),
        DB::table('funes_observations')->get()->all(),
        DB::table('aleph_ingestion_runs')->get()->all(),
        DB::table('aleph_ingestion_attempts')->get()->all(),
        $result->toArray(),
    ], JSON_THROW_ON_ERROR);

    expect($ordinaryPersistence)->not->toContain('telegram-bot-secret', 'bot_token', 'authorization');
});
