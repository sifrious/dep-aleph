<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Email;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class F28EmailMessage
{
    /**
     * @param  list<EmailParticipant>  $participants
     * @param  array<string, list<string>>  $headers
     * @param  list<EmailBody>  $bodies
     * @param  list<string>  $labels
     * @param  list<string>  $folders
     * @param  list<string>  $flags
     * @param  list<EmailAttachment>  $attachments
     */
    public function __construct(
        public EmailProvider $provider,
        public string $mailboxReference,
        public string $providerId,
        public string $providerRevision,
        public EmailChangeKind $change,
        public ?string $rfcMessageId,
        public ?string $threadId,
        public ?string $inReplyTo,
        public ?string $subject,
        public array $participants,
        public array $headers,
        public array $bodies,
        public array $labels,
        public array $folders,
        public array $flags,
        public array $attachments,
        public ?DateTimeImmutable $sentAt,
        public ?DateTimeImmutable $receivedAt,
        public string $rawReference,
    ) {
        if (trim($mailboxReference) === '' || trim($providerId) === '' || trim($providerRevision) === '' || trim($rawReference) === '') {
            throw new InvalidArgumentException('F28 email messages require mailbox, provider, revision, and raw references.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'contract' => 'F28',
            'provider' => $this->provider->value,
            'mailbox_reference' => $this->mailboxReference,
            'provider_id' => $this->providerId,
            'provider_revision' => $this->providerRevision,
            'change' => $this->change->value,
            'rfc_message_id' => $this->rfcMessageId,
            'thread_id' => $this->threadId,
            'in_reply_to' => $this->inReplyTo,
            'subject' => $this->subject,
            'participants' => array_map(static fn (EmailParticipant $participant): array => $participant->toArray(), $this->participants),
            'headers' => $this->headers,
            'bodies' => array_map(static fn (EmailBody $body): array => $body->toArray(), $this->bodies),
            'labels' => $this->labels,
            'folders' => $this->folders,
            'flags' => $this->flags,
            'attachments' => array_map(static fn (EmailAttachment $attachment): array => $attachment->toArray(), $this->attachments),
            'sent_at' => $this->sentAt?->format(DATE_ATOM),
            'received_at' => $this->receivedAt?->format(DATE_ATOM),
            'raw_reference' => $this->rawReference,
        ];
    }
}
