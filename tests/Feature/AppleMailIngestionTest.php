<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Connector\AppleMail\AppleMailAttachmentSubmission;
use Sifrious\Aleph\Connector\AppleMail\AppleMailConnector;
use Sifrious\Aleph\Connector\AppleMail\AppleMailMediaType;
use Sifrious\Aleph\Connector\AppleMail\AppleMailMessageSubmission;
use Sifrious\Aleph\Connector\AppleMail\AppleMailObservationWriter;
use Sifrious\Aleph\Connector\AppleMail\LaunchAppleMailIngestion;
use Sifrious\Aleph\Connector\AppleMail\LaunchAppleMailIngestionRequest;
use Sifrious\Aleph\Connector\AppleMail\LocalAppleMailAttachment;
use Sifrious\Aleph\Connector\AppleMail\LocalAppleMailMessage;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\LaunchAuthorization;
use Sifrious\Aleph\Ingestion\LaunchIngestion;
use Sifrious\Aleph\Ingestion\LaunchIngestionResult;
use Sifrious\Aleph\Ingestion\ManualIngestionDispatcher;
use Sifrious\Aleph\Ingestion\RunStatus;

final class AppleMailNullManualDispatcher implements ManualIngestionDispatcher
{
    public function dispatch(LaunchIngestionResult $launch): void {}
}

final class RecordingAppleMailWriter implements AppleMailObservationWriter
{
    /** @var list<AppleMailMessageSubmission> */
    public array $messages = [];

    /** @var list<AppleMailAttachmentSubmission> */
    public array $attachments = [];

    public function writeMessage(AppleMailMessageSubmission $submission, string $attemptId): string
    {
        $this->messages[] = $submission;

        return 'accepted:'.$submission->artifactReference;
    }

    public function writeAttachment(AppleMailAttachmentSubmission $submission, string $attemptId): string
    {
        $this->attachments[] = $submission;

        return 'accepted:'.$submission->artifactReference;
    }
}

/**
 * @return array{0: LaunchAppleMailIngestion, 1: mixed, 2: mixed, 3: RecordingAppleMailWriter, 4: AppleMailConnector}
 */
function appleMailLauncher(): array
{
    $registry = app(ConnectorRegistry::class);
    $connector = new AppleMailConnector;
    $registry->register($connector);
    $installations = app(ConnectorInstallations::class);
    $firstInstallation = $installations->create(
        $connector,
        'Apple Mail local mailbox',
        owner: 'identity:user/mary',
    );
    $secondInstallation = $installations->create(
        $connector,
        'Apple Mail local mailbox backup',
        owner: 'identity:user/mary',
    );
    $launch = new LaunchIngestion(
        $registry,
        $installations,
        app(IngestionRuns::class),
        new AppleMailNullManualDispatcher,
    );
    $writer = new RecordingAppleMailWriter;

    return [
        new LaunchAppleMailIngestion($launch, app(IngestionRuns::class), $registry, $writer),
        $firstInstallation,
        $secondInstallation,
        $writer,
        $connector,
    ];
}

function appleMailAuthorization(string $suffix): LaunchAuthorization
{
    return LaunchAuthorization::granted('identity:user/mary', 'authorization:apple-mail/'.$suffix);
}

/** PNG fixture bytes (1x1). */
function appleMailPngBytes(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true) ?: '';
}

it('ingests one local Mail message with one attachment into run, message artifact, and attachment artifact', function (): void {
    [$launcher, $installation, , $writer] = appleMailLauncher();
    $pdfBytes = "%PDF-1.4 fixture-invoice-bytes";
    $message = new LocalAppleMailMessage(
        rfcMessageId: '<mail-1@example.test>',
        subject: 'Invoice attached',
        from: ['billing@example.test'],
        to: ['mary@example.test'],
        bodies: [['media_type' => 'text/plain', 'content' => 'Please see the attached invoice.']],
        attachments: [
            new LocalAppleMailAttachment(
                partId: '2',
                filename: 'invoice.pdf',
                contents: $pdfBytes,
                declaredMediaType: 'application/octet-stream',
            ),
        ],
        mailboxPath: 'INBOX',
    );

    $result = $launcher->launch(new LaunchAppleMailIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'mail.app:INBOX',
        message: $message,
        authorization: appleMailAuthorization('1'),
    ));
    $run = app(IngestionRuns::class)->find($result->runId);

    expect($result->replayed)->toBeFalse()
        ->and($result->messageArtifactReference)->toBe('apple-mail://message/'.rawurlencode('<mail-1@example.test>'))
        ->and($run)->not->toBeNull()
        ->and($run?->status)->toBe(RunStatus::Completed)
        ->and($run?->acceptedReferences)->toHaveCount(2)
        ->and($writer->messages)->toHaveCount(1)
        ->and($writer->attachments)->toHaveCount(1)
        ->and($writer->messages[0]->rfcMessageId)->toBe('<mail-1@example.test>')
        ->and($writer->attachments[0]->partId)->toBe('2')
        ->and($writer->attachments[0]->contents)->toBe($pdfBytes)
        ->and($writer->attachments[0]->checksum)->toBe(hash('sha256', $pdfBytes))
        ->and($writer->attachments[0]->mediaType)->toBe('application/pdf')
        ->and($writer->attachments[0]->messageArtifactReference)->toBe($result->messageArtifactReference)
        ->and($writer->attachments[0]->bytes)->toBe(strlen($pdfBytes));
});

it('stores two attachment artifacts linked to one message', function (): void {
    [$launcher, $installation, , $writer] = appleMailLauncher();
    $png = appleMailPngBytes();
    $message = new LocalAppleMailMessage(
        rfcMessageId: '<mail-two-parts@example.test>',
        subject: 'Two files',
        attachments: [
            new LocalAppleMailAttachment('1', 'a.txt', 'alpha-bytes', declaredMediaType: 'text/plain'),
            new LocalAppleMailAttachment('2', 'b.png', $png, inline: true, contentId: 'cid:logo'),
        ],
    );

    $result = $launcher->launch(new LaunchAppleMailIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'mail.app:INBOX',
        message: $message,
        authorization: appleMailAuthorization('2'),
    ));

    expect($result->acceptedReferences)->toHaveCount(3)
        ->and($writer->messages)->toHaveCount(1)
        ->and($writer->attachments)->toHaveCount(2)
        ->and($writer->attachments[0]->messageArtifactReference)->toBe($result->messageArtifactReference)
        ->and($writer->attachments[1]->messageArtifactReference)->toBe($result->messageArtifactReference)
        ->and($writer->attachments[1]->inline)->toBeTrue()
        ->and($writer->attachments[1]->mediaType)->toBe('image/png');
});

it('treats a message without attachments as a valid completed run', function (): void {
    [$launcher, $installation, , $writer] = appleMailLauncher();
    $message = new LocalAppleMailMessage(
        rfcMessageId: '<mail-plain@example.test>',
        subject: 'No attachments',
        bodies: [['media_type' => 'text/plain', 'content' => 'Just text.']],
    );

    $result = $launcher->launch(new LaunchAppleMailIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'mail.app:INBOX',
        message: $message,
        authorization: appleMailAuthorization('3'),
    ));
    $run = app(IngestionRuns::class)->find($result->runId);

    expect($run?->status)->toBe(RunStatus::Completed)
        ->and($writer->messages)->toHaveCount(1)
        ->and($writer->attachments)->toHaveCount(0)
        ->and($result->acceptedReferences)->toHaveCount(1)
        ->and($result->attachmentFailures)->toBe([]);
});

it('replays the same message without duplicating message or attachment history', function (): void {
    [$launcher, $installation, , $writer] = appleMailLauncher();
    $message = new LocalAppleMailMessage(
        rfcMessageId: '<mail-replay@example.test>',
        subject: 'Replay me',
        attachments: [
            new LocalAppleMailAttachment('1', 'note.txt', 'same-bytes'),
        ],
    );
    $authorization = appleMailAuthorization('4');

    $first = $launcher->launch(new LaunchAppleMailIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'mail.app:INBOX',
        message: $message,
        authorization: $authorization,
    ));
    $duplicate = $launcher->launch(new LaunchAppleMailIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'mail.app:INBOX',
        message: $message,
        authorization: $authorization,
    ));

    expect($duplicate->replayed)->toBeTrue()
        ->and($duplicate->runId)->toBe($first->runId)
        ->and(DB::table('aleph_ingestion_runs')->count())->toBe(1)
        ->and($writer->messages)->toHaveCount(1)
        ->and($writer->attachments)->toHaveCount(1);
});

it('records missing attachment bytes as a partial failure while still storing the message', function (): void {
    [$launcher, $installation, , $writer] = appleMailLauncher();
    $message = new LocalAppleMailMessage(
        rfcMessageId: '<mail-missing@example.test>',
        subject: 'Missing part bytes',
        attachments: [
            new LocalAppleMailAttachment('1', 'present.txt', 'present-bytes'),
            new LocalAppleMailAttachment('2', 'missing.pdf', contents: null),
        ],
    );

    $result = $launcher->launch(new LaunchAppleMailIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'mail.app:INBOX',
        message: $message,
        authorization: appleMailAuthorization('5'),
    ));
    $run = app(IngestionRuns::class)->find($result->runId);

    expect($run?->status)->toBe(RunStatus::Partial)
        ->and($writer->messages)->toHaveCount(1)
        ->and($writer->attachments)->toHaveCount(1)
        ->and($writer->attachments[0]->partId)->toBe('1')
        ->and($result->attachmentFailures)->toBe([
            ['part_id' => '2', 'reason' => 'missing_attachment_bytes'],
        ])
        ->and($run?->acceptedReferences)->toHaveCount(2)
        ->and($run?->failure?->kind)->toBe('apple_mail_missing_attachment_bytes')
        ->and($run?->stats['attachments_missing'] ?? null)->toBe(1);
});

it('scopes message idempotency to the installation', function (): void {
    [$launcher, $installationA, $installationB, $writer] = appleMailLauncher();
    $message = new LocalAppleMailMessage(
        rfcMessageId: '<mail-shared@example.test>',
        subject: 'Shared id across installs',
        attachments: [new LocalAppleMailAttachment('1', 'a.txt', 'bytes')],
    );
    $authorization = appleMailAuthorization('6');

    $first = $launcher->launch(new LaunchAppleMailIngestionRequest(
        sourceInstallationId: $installationA->id,
        sourceReference: 'mail.app:INBOX:a',
        message: $message,
        authorization: $authorization,
    ));
    $second = $launcher->launch(new LaunchAppleMailIngestionRequest(
        sourceInstallationId: $installationB->id,
        sourceReference: 'mail.app:INBOX:b',
        message: $message,
        authorization: $authorization,
    ));

    expect($first->runId)->not->toBe($second->runId)
        ->and($first->replayed)->toBeFalse()
        ->and($second->replayed)->toBeFalse()
        ->and(DB::table('aleph_ingestion_runs')->count())->toBe(2)
        ->and($writer->messages)->toHaveCount(2)
        ->and($writer->attachments)->toHaveCount(2);
});

it('implements a real downloadArtifact for local mailbox messages', function (): void {
    [, , , , $connector] = appleMailLauncher();
    $message = new LocalAppleMailMessage(
        rfcMessageId: 'mail-download@example.test',
        subject: 'Download probe',
        attachments: [
            new LocalAppleMailAttachment('3', 'probe.txt', 'probe-bytes'),
        ],
    );

    $artifact = $connector->downloadArtifact(new ArtifactRequest(
        sourceReference: 'mail.app:INBOX',
        artifactReference: 'apple-mail://message/'.rawurlencode('<mail-download@example.test>'),
        parameters: [
            'input' => 'local_mailbox_message',
            'message' => $message->toArray(),
        ],
    ));
    $decoded = json_decode($artifact->contents, true, 512, JSON_THROW_ON_ERROR);

    expect($artifact->mediaType)->toBe('application/json')
        ->and($artifact->metadata['source'] ?? null)->toBe('mail.app.local_mailbox')
        ->and($decoded['rfc_message_id'] ?? null)->toBe('<mail-download@example.test>')
        ->and($decoded['attachments'][0]['contents_base64'] ?? null)->toBe(base64_encode('probe-bytes'));
});

it('computes attachment media types from bytes rather than declared types', function (): void {
    expect(AppleMailMediaType::fromBytes("%PDF-1.7 bytes", 'wrong.txt', 'text/plain'))->toBe('application/pdf')
        ->and(AppleMailMediaType::fromBytes(appleMailPngBytes(), 'x.bin', 'application/octet-stream'))->toBe('image/png');
});
