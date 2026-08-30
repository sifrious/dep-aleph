<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\AppleMail;

use InvalidArgumentException;
use Sifrious\Aleph\Envelope\ArtifactReference;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ExtensionMetadata;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;

final readonly class FunesAppleMailObservationWriter implements AppleMailObservationWriter
{
    public function __construct(private EnvelopeSubmitter $submitter) {}

    public function writeMessage(AppleMailMessageSubmission $submission, string $attemptId): string
    {
        $outcome = $this->submitter->submit(new ObservationEnvelope(
            sourceReference: $submission->sourceReference,
            sourceName: 'apple-mail',
            resourceReference: $submission->artifactReference,
            observedAt: new \DateTimeImmutable,
            payload: $submission->payload,
            provenance: new Provenance(
                'apple-mail',
                '1.0.0',
                $submission->sourceInstallationId,
                new \DateTimeImmutable,
                $submission->runId,
                [
                    'rfc_message_id' => $submission->rfcMessageId,
                    'source' => 'mail.app.local_mailbox',
                ],
            ),
            contentType: 'application/json',
            stream: 'mailbox',
            eventType: 'communication.email.apple_mail',
            providerId: $submission->rfcMessageId,
            artifacts: [
                new ArtifactReference(
                    reference: $submission->artifactReference.'#message',
                    relationship: 'primary',
                    mediaType: 'application/json',
                    metadata: [
                        'rfc_message_id' => $submission->rfcMessageId,
                        'attachment_count' => count($submission->attachmentSummaries),
                    ],
                ),
            ],
            extensions: [
                new ExtensionMetadata('apple_mail.message', 1, array_filter([
                    'rfc_message_id' => $submission->rfcMessageId,
                    'subject' => $submission->message['subject'] ?? null,
                    'attachments' => $submission->attachmentSummaries,
                    'source' => 'mail.app.local_mailbox',
                ], static fn (mixed $value): bool => $value !== null)),
            ],
            occurredAt: $this->occurredAt($submission->message),
        ), $attemptId);
        $accepted = $outcome->acceptedId();

        if (! $outcome->isAuthoritative() || $accepted === null) {
            throw new InvalidArgumentException('Funes did not accept the Apple Mail message artifact.');
        }

        return $accepted;
    }

    public function writeAttachment(AppleMailAttachmentSubmission $submission, string $attemptId): string
    {
        $outcome = $this->submitter->submit(new ObservationEnvelope(
            sourceReference: $submission->sourceReference,
            sourceName: 'apple-mail',
            resourceReference: $submission->artifactReference,
            observedAt: new \DateTimeImmutable,
            payload: $submission->contents,
            provenance: new Provenance(
                'apple-mail',
                '1.0.0',
                $submission->sourceInstallationId,
                new \DateTimeImmutable,
                $submission->runId,
                [
                    'rfc_message_id' => $submission->rfcMessageId,
                    'part_id' => $submission->partId,
                    'message_artifact_reference' => $submission->messageArtifactReference,
                    'source' => 'mail.app.local_mailbox',
                ],
            ),
            contentType: $submission->mediaType,
            stream: 'mailbox',
            eventType: 'communication.email.apple_mail.attachment',
            providerId: $submission->rfcMessageId.'#'.$submission->partId,
            artifacts: [
                new ArtifactReference(
                    reference: $submission->artifactReference.'#bytes',
                    relationship: 'attachment',
                    mediaType: $submission->mediaType,
                    metadata: array_filter([
                        'bytes' => $submission->bytes,
                        'sha256' => $submission->checksum,
                        'filename' => $submission->filename,
                        'inline' => $submission->inline,
                        'content_id' => $submission->contentId,
                        'part_id' => $submission->partId,
                        'rfc_message_id' => $submission->rfcMessageId,
                        'message_artifact_reference' => $submission->messageArtifactReference,
                    ], static fn (mixed $value): bool => $value !== null),
                ),
            ],
            extensions: [
                new ExtensionMetadata('apple_mail.attachment', 1, array_filter([
                    'rfc_message_id' => $submission->rfcMessageId,
                    'part_id' => $submission->partId,
                    'filename' => $submission->filename,
                    'inline' => $submission->inline,
                    'content_id' => $submission->contentId,
                    'media_type' => $submission->mediaType,
                    'checksum' => [
                        'algorithm' => 'sha256',
                        'value' => $submission->checksum,
                    ],
                    'bytes' => $submission->bytes,
                    'message_artifact_reference' => $submission->messageArtifactReference,
                    'source' => 'mail.app.local_mailbox',
                ], static fn (mixed $value): bool => $value !== null)),
            ],
        ), $attemptId);
        $accepted = $outcome->acceptedId();

        if (! $outcome->isAuthoritative() || $accepted === null) {
            throw new InvalidArgumentException('Funes did not accept the Apple Mail attachment artifact.');
        }

        return $accepted;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function occurredAt(array $message): ?\DateTimeImmutable
    {
        foreach (['received_at', 'sent_at'] as $key) {
            $value = $message[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            try {
                return new \DateTimeImmutable($value);
            } catch (\Exception) {
                continue;
            }
        }

        return null;
    }
}
