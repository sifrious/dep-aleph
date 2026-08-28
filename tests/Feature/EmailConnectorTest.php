<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\Email\EmailConnector;
use Sifrious\Aleph\Connector\Email\EmailPage;
use Sifrious\Aleph\Connector\Email\EmailSource;
use Sifrious\Aleph\Connector\Email\EmailSources;
use Sifrious\Aleph\Connector\Email\F28EmailMessage;
use Sifrious\Aleph\Connector\Email\GmailMessageAdapter;
use Sifrious\Aleph\Connector\Email\ImapMessageAdapter;
use Sifrious\Aleph\Connector\Email\ImportEmailMessages;
use Sifrious\Aleph\Connector\Email\MicrosoftGraphMessageAdapter;
use Sifrious\Aleph\Connector\Values\OperationRequest;
use Sifrious\Aleph\Envelope\ObservationMetadata;
use Sifrious\Aleph\Ingestion\Capability;
use Sifrious\Aleph\Ingestion\IngestionCheckpoints;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\RunStatus;
use Sifrious\Aleph\Ingestion\SourceStreams;
use Sifrious\Funes\Persistence\ObservationStore;

final class FixtureEmailSource implements EmailSource
{
    /** @var list<?string> */
    public array $requests = [];

    /** @param array<string, EmailPage> $pages */
    public function __construct(
        private readonly string $reference,
        private readonly string $checkpoint,
        private readonly array $pages,
        public readonly string $credential = 'email-provider-secret',
    ) {}

    public function sourceReference(): string
    {
        return $this->reference;
    }

    public function checkpointType(): string
    {
        return $this->checkpoint;
    }

    public function page(?string $checkpoint, int $limit): EmailPage
    {
        $this->requests[] = $checkpoint;

        return $this->pages[$checkpoint ?? 'start'] ?? new EmailPage([], $checkpoint, false);
    }
}

function gmailRecord(string $id = 'gmail-1', string $historyId = '101', string $change = 'created'): array
{
    return [
        'id' => $id,
        'historyId' => $historyId,
        'threadId' => 'thread-1',
        'change' => $change,
        'labelIds' => ['INBOX', 'Important'],
        'internalDate' => '1787911200000',
        'access_token' => 'email-provider-secret',
        'payload' => [
            'mimeType' => 'multipart/mixed',
            'headers' => [
                ['name' => 'Message-ID', 'value' => '<message-1@example.test>'],
                ['name' => 'In-Reply-To', 'value' => '<prior@example.test>'],
                ['name' => 'Subject', 'value' => 'A multipart reply'],
                ['name' => 'From', 'value' => 'Alice Example <ALICE@Example.test>'],
                ['name' => 'To', 'value' => 'Bob Example <bob@example.test>'],
                ['name' => 'Date', 'value' => 'Fri, 28 Aug 2026 10:00:00 +0000'],
            ],
            'parts' => [
                ['mimeType' => 'text/plain', 'headers' => [], 'body' => ['data' => rtrim(strtr(base64_encode('Plain body'), '+/', '-_'), '=')]],
                ['mimeType' => 'text/html', 'headers' => [], 'body' => ['data' => rtrim(strtr(base64_encode('<p>HTML body</p>'), '+/', '-_'), '=')]],
                ['mimeType' => 'application/pdf', 'filename' => 'invoice.pdf', 'headers' => [], 'body' => ['attachmentId' => 'attachment-1', 'size' => 42]],
            ],
        ],
    ];
}

function emailConnector(): EmailConnector
{
    return new EmailConnector(app(ImportEmailMessages::class));
}

function emailInstallation(EmailConnector $connector, string $mailbox): object
{
    return app(ConnectorInstallations::class)->create(
        $connector,
        $mailbox,
        externalAccountId: $mailbox,
        funesSourceAccountId: 'source-account:'.$mailbox,
    );
}

function emailOperation(
    EmailConnector $connector,
    object $installation,
    string $mailbox,
    Capability $capability,
    int $expectedVersion = 0,
    int $maxPages = 100,
    ?object $stream = null,
): array {
    $stream ??= app(SourceStreams::class)->create($installation->id, $mailbox);
    $run = app(IngestionRuns::class)->start($mailbox, $capability, [], $connector->id(), $installation->id);
    $attempt = app(IngestionRuns::class)->beginAttempt($run);
    $request = new OperationRequest($mailbox, [
        'stream_id' => $stream->id,
        'run_id' => $run->id,
        'attempt_id' => $attempt->id,
        'expected_checkpoint_version' => $expectedVersion,
        'page_size' => 50,
        'max_pages' => $maxPages,
    ]);
    $result = $capability === Capability::Backfill
        ? $connector->backfill($request)
        : $connector->syncIncrementally($request);

    return [$result, $stream, $run, $attempt];
}

it('normalizes Gmail Graph and IMAP fixtures to F28 without provider SDK values', function (): void {
    $mailbox = 'email:mailbox/alice';
    $gmail = (new GmailMessageAdapter)->adapt(gmailRecord(), $mailbox, 'gmail:raw/1');
    $graph = (new MicrosoftGraphMessageAdapter)->adapt([
        'id' => 'graph-1',
        'changeKey' => 'change-1',
        'change' => 'created',
        'internetMessageId' => '<graph@example.test>',
        'conversationId' => 'conversation-1',
        'subject' => 'Graph message',
        'from' => ['emailAddress' => ['name' => 'Alice', 'address' => 'ALICE@example.test']],
        'toRecipients' => [['emailAddress' => ['name' => 'Bob', 'address' => 'bob@example.test']]],
        'internetMessageHeaders' => [['name' => 'In-Reply-To', 'value' => '<prior@example.test>']],
        'body' => ['contentType' => 'html', 'content' => '<p>Graph body</p>'],
        'attachments' => [['id' => 'graph-attachment', 'name' => 'graph.txt', 'contentType' => 'text/plain', 'size' => 12]],
        'categories' => ['Follow up'],
        'parentFolderId' => 'inbox',
        'receivedDateTime' => '2026-08-28T10:00:00Z',
    ], $mailbox, 'graph:raw/1');
    $imap = (new ImapMessageAdapter)->adapt([
        'uid' => 7,
        'uidvalidity' => 900,
        'modseq' => 4,
        'change' => 'created',
        'headers' => [
            'Message-ID' => '<imap@example.test>',
            'From' => 'Alice <ALICE@example.test>',
            'To' => 'Bob <bob@example.test>',
            'Subject' => 'IMAP message',
        ],
        'bodies' => [['media_type' => 'text/plain', 'content' => 'IMAP body']],
        'attachments' => [['part_id' => '2', 'filename' => 'imap.txt', 'media_type' => 'text/plain', 'size' => 9]],
        'folders' => ['INBOX'],
        'flags' => ['\\Seen'],
    ], $mailbox, 'imap:raw/900/7');

    foreach ([$gmail, $graph, $imap] as $message) {
        $serialized = json_encode($message->toArray(), JSON_THROW_ON_ERROR);

        expect($message)->toBeInstanceOf(F28EmailMessage::class)
            ->and($message->toArray()['contract'])->toBe('F28')
            ->and($serialized)->not->toContain('Google\\', 'Microsoft\\Graph\\', 'access_token', 'email-provider-secret');
    }

    expect($gmail->bodies)->toHaveCount(2)
        ->and($gmail->participants[0]->original)->toBe('Alice Example <ALICE@Example.test>')
        ->and($gmail->participants[0]->address)->toBe('alice@example.test')
        ->and($gmail->inReplyTo)->toBe('<prior@example.test>')
        ->and($graph->bodies[0]->mediaType)->toBe('text/html')
        ->and($imap->providerId)->toBe('900:7');
});

it('pauses a bounded backfill at a durable provider checkpoint and resumes from it', function (): void {
    $mailbox = 'email:mailbox/alice';
    $adapter = new GmailMessageAdapter;
    $source = new FixtureEmailSource($mailbox, 'gmail.history-id', [
        'start' => new EmailPage([$adapter->adapt(gmailRecord('gmail-1', '101'), $mailbox, 'gmail:raw/1')], 'history-101', true),
        'history-101' => new EmailPage([$adapter->adapt(gmailRecord('gmail-2', '102'), $mailbox, 'gmail:raw/2')], 'history-102', false),
    ]);
    app(EmailSources::class)->register($source);
    $connector = emailConnector();
    $installation = emailInstallation($connector, $mailbox);
    [$partial, $stream, $pausedRun] = emailOperation($connector, $installation, $mailbox, Capability::Backfill, maxPages: 1);
    [$complete] = emailOperation($connector, $installation, $mailbox, Capability::Backfill, 1, stream: $stream);
    $checkpoint = app(IngestionCheckpoints::class)->latest($stream, Capability::Backfill, 'mailbox');
    expect($partial->error)->toBeNull()
        ->and($partial->successful)->toBeTrue()
        ->and($partial->complete)->toBeFalse()
        ->and($partial->cursor)->toBe('history-101')
        ->and($complete->complete)->toBeTrue()
        ->and($source->requests)->toBe([null, 'history-101'])
        ->and($checkpoint?->value->format)->toBe('gmail.history-id')
        ->and($checkpoint?->value->value)->toBe('history-102')
        ->and($checkpoint?->version)->toBe(2)
        ->and(app(IngestionRuns::class)->find($pausedRun->id)?->status)->toBe(RunStatus::Partial)
        ->and(DB::table('funes_observations')->count())->toBe(2);
});

it('records edits labels folders and deletion while duplicate replay stays idempotent', function (): void {
    $mailbox = 'email:mailbox/alice';
    $adapter = new GmailMessageAdapter;
    $created = $adapter->adapt(gmailRecord('gmail-1', '101', 'created'), $mailbox, 'gmail:raw/1');
    $updatedRecord = gmailRecord('gmail-1', '102', 'updated');
    $updatedRecord['labelIds'] = ['Archive'];
    $updated = $adapter->adapt($updatedRecord, $mailbox, 'gmail:raw/2');
    $deletedRecord = gmailRecord('gmail-1', '103', 'deleted');
    $deletedRecord['deleted'] = true;
    $deleted = $adapter->adapt($deletedRecord, $mailbox, 'gmail:raw/3');
    $source = new FixtureEmailSource($mailbox, 'gmail.history-id', [
        'start' => new EmailPage([$created, $updated, $deleted, $deleted], 'history-103', false),
    ]);
    app(EmailSources::class)->register($source);
    $connector = emailConnector();
    [$result] = emailOperation($connector, emailInstallation($connector, $mailbox), $mailbox, Capability::IncrementalSync);
    $payloads = DB::table('funes_payloads')->pluck('contents')->map(
        static fn (string $contents): array => json_decode($contents, true, 512, JSON_THROW_ON_ERROR),
    );

    expect($result->error)->toBeNull()
        ->and($result->successful)->toBeTrue()
        ->and($result->records)->toBe(3)
        ->and(DB::table('funes_observations')->count())->toBe(3)
        ->and($payloads->pluck('change')->all())->toContain('created', 'updated', 'deleted')
        ->and($payloads->firstWhere('provider_revision', '102')['labels'])->toBe(['Archive']);
});

it('keeps credentials outside F28 persistence logs exports and Funes', function (): void {
    $mailbox = 'email:mailbox/alice';
    $message = (new GmailMessageAdapter)->adapt(gmailRecord(), $mailbox, 'gmail:raw/1');
    app(EmailSources::class)->register(new FixtureEmailSource($mailbox, 'gmail.history-id', [
        'start' => new EmailPage([$message], 'history-101', false),
    ]));
    $connector = emailConnector();
    [$result] = emailOperation($connector, emailInstallation($connector, $mailbox), $mailbox, Capability::IncrementalSync);
    $ordinaryPersistence = json_encode([
        DB::table('funes_payloads')->get()->all(),
        DB::table('funes_observations')->get()->all(),
        DB::table('aleph_ingestion_runs')->get()->all(),
        DB::table('aleph_ingestion_attempts')->get()->all(),
        $result->toArray(),
    ], JSON_THROW_ON_ERROR);

    expect($ordinaryPersistence)->not->toContain('email-provider-secret', 'access_token', 'authorization');
});

it('retains attachment references for Digory without resolving people or storing attachment bytes', function (): void {
    $mailbox = 'email:mailbox/alice';
    $message = (new GmailMessageAdapter)->adapt(gmailRecord(), $mailbox, 'gmail:raw/1');
    app(EmailSources::class)->register(new FixtureEmailSource($mailbox, 'gmail.history-id', [
        'start' => new EmailPage([$message], 'history-101', false),
    ]));
    $connector = emailConnector();
    emailOperation($connector, emailInstallation($connector, $mailbox), $mailbox, Capability::IncrementalSync);
    $observation = app(ObservationStore::class)->get((string) DB::table('funes_observations')->value('id'));
    $metadata = $observation === null ? [] : ObservationMetadata::extension($observation, 'communication.f28');
    $payload = (string) DB::table('funes_payloads')->value('contents');

    expect($metadata['attachment_references'][0]['historical_reference'])->toBe('digory:email-attachment/gmail/gmail-1/attachment-1')
        ->and($payload)->toContain('Alice Example <ALICE@Example.test>')
        ->and($payload)->not->toContain('person_id', 'identity_id', 'Plain body attachment bytes')
        ->and(DB::getSchemaBuilder()->hasTable('aleph_email_attachments'))->toBeFalse();
});
