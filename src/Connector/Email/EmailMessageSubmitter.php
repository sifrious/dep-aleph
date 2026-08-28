<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Email;

use DateTimeImmutable;
use InvalidArgumentException;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ExtensionMetadata;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;

final readonly class EmailMessageSubmitter
{
    public function __construct(private EnvelopeSubmitter $submitter) {}

    public function submit(
        F28EmailMessage $message,
        string $installationId,
        ?string $account,
        DateTimeImmutable $capturedAt,
        ?string $runId = null,
        ?string $attemptId = null,
    ): string {
        $outcome = $this->submitter->submit(new ObservationEnvelope(
            sourceReference: $message->mailboxReference,
            sourceName: $message->mailboxReference,
            resourceReference: $message->mailboxReference.'/'.$message->provider->value.'/'.$message->providerId,
            observedAt: $capturedAt,
            payload: json_encode($message->toArray(), JSON_THROW_ON_ERROR),
            provenance: new Provenance('email', '1.0.0', $installationId, $capturedAt, $runId, [
                'raw_reference' => $message->rawReference,
            ]),
            contentType: 'application/json',
            account: $account,
            stream: 'mailbox',
            eventType: 'communication.email',
            providerId: $message->providerId,
            providerRevision: $message->providerRevision,
            extensions: [new ExtensionMetadata('communication.f28', 1, [
                'provider' => $message->provider->value,
                'change' => $message->change->value,
                'attachment_references' => array_map(
                    static fn (EmailAttachment $attachment): array => $attachment->toArray(),
                    $message->attachments,
                ),
            ])],
            occurredAt: $message->receivedAt ?? $message->sentAt,
        ), $attemptId);
        $accepted = $outcome->acceptedId();

        if (! $outcome->isAuthoritative() || $accepted === null) {
            throw new InvalidArgumentException('Funes did not accept the email message.');
        }

        return $accepted;
    }
}
