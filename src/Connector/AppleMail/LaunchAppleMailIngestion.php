<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\AppleMail;

use InvalidArgumentException;
use Sifrious\Aleph\Connector\Capability;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\Contracts\DownloadsArtifacts;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\LaunchIngestion;
use Sifrious\Aleph\Ingestion\LaunchIngestionRequest;
use Sifrious\Aleph\Ingestion\RunFailure;
use Throwable;

final readonly class LaunchAppleMailIngestion
{
    public function __construct(
        private LaunchIngestion $launcher,
        private IngestionRuns $runs,
        private ConnectorRegistry $connectors,
        private AppleMailObservationWriter $writer,
    ) {}

    public function launch(LaunchAppleMailIngestionRequest $request): LaunchAppleMailIngestionResult
    {
        $message = $request->message;
        $messageId = $message->normalizedMessageId();
        $messageArtifactReference = 'apple-mail://message/'.rawurlencode($messageId);
        $prepared = $this->prepare($request, $messageArtifactReference, $messageId);

        $launch = $this->launcher->launch(new LaunchIngestionRequest(
            sourceInstallationId: $request->sourceInstallationId,
            sourceReference: $request->sourceReference,
            capability: Capability::DownloadsArtifacts,
            parameters: $prepared['run_parameters'],
            idempotencyKey: 'apple-mail:'.$messageId,
            authorization: $request->authorization,
        ));
        $run = $launch->run;

        if ($launch->replayed) {
            return new LaunchAppleMailIngestionResult(
                $run->id,
                true,
                $messageArtifactReference,
                $run->acceptedReferences,
            );
        }

        $existing = $this->runs->find($run->id);

        if ($existing !== null && $existing->acceptedReferences !== []) {
            return new LaunchAppleMailIngestionResult(
                $existing->id,
                false,
                $messageArtifactReference,
                $existing->acceptedReferences,
            );
        }

        if ($existing !== null && $this->runs->activeAttempt($existing) !== null) {
            return new LaunchAppleMailIngestionResult(
                $existing->id,
                false,
                $messageArtifactReference,
                $existing->acceptedReferences,
            );
        }

        $attempt = $this->runs->beginAttempt($run);
        $attachmentFailures = [];

        try {
            $connector = $this->connectors->get($run->connectorId ?? '');

            if (! $connector instanceof DownloadsArtifacts) {
                throw new InvalidArgumentException('The run connector does not support artifact downloads.');
            }

            $artifact = $connector->downloadArtifact(new ArtifactRequest(
                sourceReference: $request->sourceReference,
                artifactReference: $messageArtifactReference,
                parameters: $prepared['connector_parameters'],
            ));

            $decoded = json_decode($artifact->contents, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($decoded)) {
                throw new InvalidArgumentException('Apple Mail artifact contents must decode to a message object.');
            }

            $downloaded = LocalAppleMailMessage::fromArray($decoded);

            if ($downloaded->normalizedMessageId() !== $messageId) {
                throw new InvalidArgumentException('Apple Mail Message-ID changed between launch and artifact download.');
            }

            $attachmentSummaries = [];
            $attachmentFailures = [];
            $accepted = [];

            foreach ($downloaded->attachments as $attachment) {
                $summary = [
                    'part_id' => $attachment->partId,
                    'filename' => $attachment->filename,
                    'inline' => $attachment->inline,
                    'content_id' => $attachment->contentId,
                    'bytes_present' => $attachment->hasBytes(),
                ];

                if ($attachment->hasBytes()) {
                    $contents = $attachment->contents ?? '';
                    $summary['sha256'] = hash('sha256', $contents);
                    $summary['media_type'] = AppleMailMediaType::fromBytes(
                        $contents,
                        $attachment->filename,
                        $attachment->declaredMediaType,
                    );
                    $summary['bytes'] = strlen($contents);
                }

                $attachmentSummaries[] = $summary;
            }

            $messagePayload = json_encode(array_filter([
                'rfc_message_id' => $downloaded->normalizedMessageId(),
                'subject' => $downloaded->subject,
                'from' => $downloaded->from,
                'to' => $downloaded->to,
                'cc' => $downloaded->cc,
                'bodies' => $downloaded->bodies,
                'attachments' => $attachmentSummaries,
                'sent_at' => $downloaded->sentAt?->format(DATE_ATOM),
                'received_at' => $downloaded->receivedAt?->format(DATE_ATOM),
                'mailbox_path' => $downloaded->mailboxPath,
                'source' => 'mail.app.local_mailbox',
            ], static fn (mixed $value): bool => $value !== null && $value !== []), JSON_THROW_ON_ERROR);

            $accepted[] = $this->writer->writeMessage(new AppleMailMessageSubmission(
                sourceReference: $request->sourceReference,
                sourceInstallationId: $request->sourceInstallationId,
                runId: $run->id,
                artifactReference: $messageArtifactReference,
                rfcMessageId: $messageId,
                payload: $messagePayload,
                message: $downloaded->toArray(),
                attachmentSummaries: $attachmentSummaries,
            ), $attempt->id);

            $storedAttachments = 0;

            foreach ($downloaded->attachments as $attachment) {
                if (! $attachment->hasBytes()) {
                    $attachmentFailures[] = [
                        'part_id' => $attachment->partId,
                        'reason' => 'missing_attachment_bytes',
                    ];

                    continue;
                }

                $contents = $attachment->contents ?? '';
                $checksum = hash('sha256', $contents);
                $mediaType = AppleMailMediaType::fromBytes(
                    $contents,
                    $attachment->filename,
                    $attachment->declaredMediaType,
                );
                $attachmentReference = $messageArtifactReference.'/part/'.rawurlencode($attachment->partId);

                $accepted[] = $this->writer->writeAttachment(new AppleMailAttachmentSubmission(
                    sourceReference: $request->sourceReference,
                    sourceInstallationId: $request->sourceInstallationId,
                    runId: $run->id,
                    messageArtifactReference: $messageArtifactReference,
                    rfcMessageId: $messageId,
                    partId: $attachment->partId,
                    artifactReference: $attachmentReference,
                    mediaType: $mediaType,
                    contents: $contents,
                    checksum: $checksum,
                    bytes: strlen($contents),
                    filename: $attachment->filename,
                    inline: $attachment->inline,
                    contentId: $attachment->contentId,
                ), $attempt->id);
                $storedAttachments++;
            }

            $stats = [
                'artifacts' => 1 + $storedAttachments,
                'accepted' => count($accepted),
                'attachments_declared' => count($downloaded->attachments),
                'attachments_stored' => $storedAttachments,
                'attachments_missing' => count($attachmentFailures),
            ];

            if ($attachmentFailures !== []) {
                $this->runs->failAttempt(
                    $run,
                    $attempt,
                    new RunFailure(
                        'apple_mail_missing_attachment_bytes',
                        'One or more Apple Mail attachments were missing bytes; the message artifact was stored.',
                        true,
                        ['failures' => $attachmentFailures],
                    ),
                    $stats,
                    $accepted,
                    partial: true,
                    remainingWork: array_map(
                        static fn (array $failure): array => [
                            'partition' => 'attachment:'.$failure['part_id'],
                            'reason' => $failure['reason'],
                        ],
                        $attachmentFailures,
                    ),
                    warningCount: count($attachmentFailures),
                    errorCount: count($attachmentFailures),
                );
            } else {
                $this->runs->succeedAttempt($run, $attempt, $stats, $accepted);
            }
        } catch (Throwable $failure) {
            $this->runs->failAttempt(
                $run,
                $attempt,
                new RunFailure('apple_mail_ingestion', $failure->getMessage(), true, ['failure' => $failure::class]),
            );

            throw $failure;
        }

        $fresh = $this->runs->find($run->id) ?? $run;

        return new LaunchAppleMailIngestionResult(
            $fresh->id,
            false,
            $messageArtifactReference,
            $fresh->acceptedReferences,
            $attachmentFailures,
        );
    }

    /**
     * @return array{
     *     run_parameters: array<string, mixed>,
     *     connector_parameters: array<string, mixed>
     * }
     */
    private function prepare(
        LaunchAppleMailIngestionRequest $request,
        string $messageArtifactReference,
        string $messageId,
    ): array {
        if (trim($request->sourceReference) === '') {
            throw new InvalidArgumentException('Apple Mail ingestion requires a stable mailbox source reference.');
        }

        $message = $request->message->toArray();

        return [
            'run_parameters' => array_filter([
                'input' => 'local_mailbox_message',
                'source' => 'mail.app.local_mailbox',
                'rfc_message_id' => $messageId,
                'artifact_reference' => $messageArtifactReference,
                'subject' => $request->message->subject,
                'attachment_count' => count($request->message->attachments),
                'mailbox_path' => $request->message->mailboxPath,
            ], static fn (mixed $value): bool => $value !== null),
            'connector_parameters' => [
                'input' => 'local_mailbox_message',
                'message' => $message,
            ],
        ];
    }
}
