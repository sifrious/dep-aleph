<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Connector\Communication\CommunicationPage;
use Sifrious\Aleph\Connector\Communication\CommunicationProvider;
use Sifrious\Aleph\Connector\Communication\CommunicationSource;
use Sifrious\Aleph\Connector\Communication\CommunicationSources;
use Sifrious\Aleph\Connector\Communication\ImportCommunicationRecords;
use Sifrious\Aleph\Connector\Communication\ProviderCommunicationConnector;
use Sifrious\Aleph\Connector\Communication\SmsMessageAdapter;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\Values\OperationRequest;
use Sifrious\Aleph\Ingestion\Capability;
use Sifrious\Aleph\Ingestion\IngestionCheckpoints;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\SourceStreams;

final class FixtureSmsSource implements CommunicationSource
{
    /** @var list<?string> */
    public array $requests = [];

    /** @param array<string, CommunicationPage> $pages */
    public function __construct(
        private readonly string $reference,
        private readonly array $pages,
        public readonly string $backupKey = 'sms-backup-secret',
    ) {}

    public function sourceReference(): string
    {
        return $this->reference;
    }

    public function provider(): CommunicationProvider
    {
        return CommunicationProvider::Sms;
    }

    public function checkpointType(): string
    {
        return 'sms.device-row';
    }

    public function page(?string $checkpoint, int $limit): CommunicationPage
    {
        $this->requests[] = $checkpoint;

        return $this->pages[$checkpoint ?? 'start'] ?? new CommunicationPage([], $checkpoint, false);
    }
}

function smsRecord(string $id = 'sms-1', array $overrides = []): array
{
    return array_replace_recursive([
        'message_id' => $id,
        'revision' => 'row-101',
        'conversation_id' => 'thread-1',
        'direction' => 'inbound',
        'from' => '(607) 555-0101',
        'to' => '+1 607 555 0199',
        'occurred_at' => '2026-08-28T10:00:00Z',
        'body' => 'Hello by SMS',
        'delivery_state' => 'delivered',
        'collector' => 'device-backup',
        'backup_password' => 'sms-backup-secret',
    ], $overrides);
}

function smsConnector(): ProviderCommunicationConnector
{
    return new ProviderCommunicationConnector(CommunicationProvider::Sms, app(ImportCommunicationRecords::class));
}

function smsOperation(ProviderCommunicationConnector $connector, object $installation, string $source, int $version = 0, int $maxPages = 100, ?object $stream = null): array
{
    $stream ??= app(SourceStreams::class)->create($installation->id, $source);
    $run = app(IngestionRuns::class)->start($source, Capability::Backfill, [], $connector->id(), $installation->id);
    $attempt = app(IngestionRuns::class)->beginAttempt($run);
    $result = $connector->backfill(new OperationRequest($source, [
        'stream_id' => $stream->id,
        'run_id' => $run->id,
        'attempt_id' => $attempt->id,
        'expected_checkpoint_version' => $version,
        'page_size' => 50,
        'max_pages' => $maxPages,
    ]));

    return [$result, $stream];
}

it('normalizes inbound outbound group MMS and attachments without asserting person identity', function (): void {
    $adapter = new SmsMessageAdapter;
    $inbound = $adapter->adapt(smsRecord(), 'sms:device/1', 'sms:row/101');
    $outbound = $adapter->adapt(smsRecord('sms-2', [
        'revision' => 'row-102',
        'direction' => 'outbound',
        'from' => '+1 607 555 0199',
        'to' => '607.555.0101',
    ]), 'sms:device/1', 'sms:row/102');
    $group = $adapter->adapt(smsRecord('mms-1', [
        'revision' => 'row-103',
        'conversation_id' => 'thread-group',
        'conversation_kind' => 'group_mms',
        'participants' => ['(607) 555-0101', '+1 607 555 0102', 'short-code'],
        'attachments' => [[
            'id' => 'part-1',
            'filename' => 'photo.jpg',
            'media_type' => 'image/jpeg',
            'size' => 42,
        ]],
    ]), 'sms:device/1', 'sms:row/103');

    expect($inbound->reconciliation['direction'])->toBe('inbound')
        ->and($outbound->reconciliation['direction'])->toBe('outbound')
        ->and($inbound->participants[0]->originalAddress)->toBe('(607) 555-0101')
        ->and($inbound->participants[0]->normalizedAddress)->toBe('+16075550101')
        ->and($inbound->participants[0]->kind)->toBe('source_address')
        ->and($group->conversationKind)->toBe('group_mms')
        ->and($group->participants)->toHaveCount(4)
        ->and($group->attachments[0]->digoryReference)->toBe('digory:sms-attachment/mms-1/part-1')
        ->and(json_encode([$inbound->toArray(), $outbound->toArray(), $group->toArray()], JSON_THROW_ON_ERROR))
        ->not->toContain('sms-backup-secret', 'backup_password', 'person_id', 'Twilio\\');
});

it('replays duplicate SMS imports idempotently and resumes from an accepted checkpoint', function (): void {
    $adapter = new SmsMessageAdapter;
    $sourceReference = 'sms:device/1';
    $record = $adapter->adapt(smsRecord(), $sourceReference, 'sms:row/101');
    $source = new FixtureSmsSource($sourceReference, [
        'start' => new CommunicationPage([$record, $record], 'row-101', true),
        'row-101' => new CommunicationPage([$adapter->adapt(smsRecord('sms-2', ['revision' => 'row-102']), $sourceReference, 'sms:row/102')], 'row-102', false),
    ]);
    app(CommunicationSources::class)->register($source);
    $connector = smsConnector();
    $installation = app(ConnectorInstallations::class)->create($connector, $sourceReference, externalAccountId: 'device-1', funesSourceAccountId: 'source-account:sms-1');
    [$partial, $stream] = smsOperation($connector, $installation, $sourceReference, maxPages: 1);
    [$complete] = smsOperation($connector, $installation, $sourceReference, 1, stream: $stream);
    $checkpoint = app(IngestionCheckpoints::class)->latest($stream, Capability::Backfill, 'conversation');

    expect($partial->complete)->toBeFalse()
        ->and($partial->records)->toBe(1)
        ->and($complete->complete)->toBeTrue()
        ->and($source->requests)->toBe([null, 'row-101'])
        ->and($checkpoint?->value->format)->toBe('sms.device-row')
        ->and($checkpoint?->value->value)->toBe('row-102')
        ->and(DB::table('funes_observations')->count())->toBe(2);
});

it('keeps SMS provider and backup secrets outside persistence results and Funes', function (): void {
    $sourceReference = 'sms:device/1';
    $record = (new SmsMessageAdapter)->adapt(smsRecord(), $sourceReference, 'sms:row/101');
    app(CommunicationSources::class)->register(new FixtureSmsSource($sourceReference, [
        'start' => new CommunicationPage([$record], 'row-101', false),
    ]));
    $connector = smsConnector();
    $installation = app(ConnectorInstallations::class)->create($connector, $sourceReference, externalAccountId: 'device-1', funesSourceAccountId: 'source-account:sms-1');
    [$result] = smsOperation($connector, $installation, $sourceReference);
    $ordinaryPersistence = json_encode([
        DB::table('funes_payloads')->get()->all(),
        DB::table('funes_observations')->get()->all(),
        DB::table('aleph_ingestion_runs')->get()->all(),
        DB::table('aleph_ingestion_attempts')->get()->all(),
        $result->toArray(),
    ], JSON_THROW_ON_ERROR);

    expect($ordinaryPersistence)->not->toContain('sms-backup-secret', 'backup_password', 'auth_token');
});
