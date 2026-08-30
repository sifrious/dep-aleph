<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\AppleMail;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class LocalAppleMailMessage
{
    /**
     * @param  list<string>  $from
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<array{media_type: string, content: string, content_id?: string|null}>  $bodies
     * @param  list<LocalAppleMailAttachment>  $attachments
     */
    public function __construct(
        public string $rfcMessageId,
        public ?string $subject = null,
        public array $from = [],
        public array $to = [],
        public array $cc = [],
        public array $bodies = [],
        public array $attachments = [],
        public ?DateTimeImmutable $sentAt = null,
        public ?DateTimeImmutable $receivedAt = null,
        public ?string $mailboxPath = null,
    ) {
        $normalized = self::normalizeMessageId($rfcMessageId);

        if ($normalized === '') {
            throw new InvalidArgumentException('Apple Mail messages require an RFC Message-ID.');
        }

        foreach ($attachments as $attachment) {
            if (! $attachment instanceof LocalAppleMailAttachment) {
                throw new InvalidArgumentException('Apple Mail attachments must be LocalAppleMailAttachment values.');
            }
        }
    }

    public function normalizedMessageId(): string
    {
        return self::normalizeMessageId($this->rfcMessageId);
    }

    public static function normalizeMessageId(string $messageId): string
    {
        $trimmed = trim($messageId);

        if ($trimmed === '') {
            return '';
        }

        if (str_starts_with($trimmed, '<') && str_ends_with($trimmed, '>')) {
            return $trimmed;
        }

        return '<'.trim($trimmed, "<> \t\n\r").'>';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'rfc_message_id' => $this->normalizedMessageId(),
            'subject' => $this->subject,
            'from' => $this->from,
            'to' => $this->to,
            'cc' => $this->cc,
            'bodies' => $this->bodies,
            'attachments' => array_map(
                static fn (LocalAppleMailAttachment $attachment): array => $attachment->toArray(),
                $this->attachments,
            ),
            'sent_at' => $this->sentAt?->format(DATE_ATOM),
            'received_at' => $this->receivedAt?->format(DATE_ATOM),
            'mailbox_path' => $this->mailboxPath,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $attachments = [];

        foreach (is_array($row['attachments'] ?? null) ? $row['attachments'] : [] as $attachment) {
            if (is_array($attachment)) {
                $attachments[] = LocalAppleMailAttachment::fromArray($attachment);
            }
        }

        $bodies = [];

        foreach (is_array($row['bodies'] ?? null) ? $row['bodies'] : [] as $body) {
            if (is_array($body) && is_string($body['content'] ?? null)) {
                $bodies[] = array_filter([
                    'media_type' => is_string($body['media_type'] ?? null) ? $body['media_type'] : 'text/plain',
                    'content' => $body['content'],
                    'content_id' => is_string($body['content_id'] ?? null) ? $body['content_id'] : null,
                ], static fn (mixed $value): bool => $value !== null);
            }
        }

        return new self(
            rfcMessageId: is_string($row['rfc_message_id'] ?? null) ? $row['rfc_message_id'] : '',
            subject: is_string($row['subject'] ?? null) ? $row['subject'] : null,
            from: self::strings($row['from'] ?? []),
            to: self::strings($row['to'] ?? []),
            cc: self::strings($row['cc'] ?? []),
            bodies: $bodies,
            attachments: $attachments,
            sentAt: self::date($row['sent_at'] ?? null),
            receivedAt: self::date($row['received_at'] ?? null),
            mailboxPath: is_string($row['mailbox_path'] ?? null) ? $row['mailbox_path'] : null,
        );
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $out = [];

        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $out[] = $value;
            }
        }

        return $out;
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
