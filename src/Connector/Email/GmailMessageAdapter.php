<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Email;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class GmailMessageAdapter
{
    /** @param array<string, mixed> $record */
    public function adapt(array $record, string $mailboxReference, string $rawReference): F28EmailMessage
    {
        $id = $record['id'] ?? null;
        $historyId = $record['historyId'] ?? null;
        $payload = is_array($record['payload'] ?? null) ? $record['payload'] : [];

        if (! is_string($id) || (! is_string($historyId) && ! is_int($historyId))) {
            throw new InvalidArgumentException('Gmail messages require id and historyId values.');
        }

        $headers = EmailAdapterValues::headers(is_array($payload['headers'] ?? null) ? $payload['headers'] : []);
        [$bodies, $attachments] = $this->parts($payload, $id);
        $participants = [];

        foreach (['from', 'to', 'cc', 'bcc', 'reply-to'] as $role) {
            array_push($participants, ...EmailAdapterValues::participants($role, EmailAdapterValues::header($headers, $role)));
        }

        $internalDate = is_string($record['internalDate'] ?? null) && ctype_digit($record['internalDate'])
            ? (new DateTimeImmutable)->setTimestamp((int) floor(((int) $record['internalDate']) / 1000))
            : null;

        return new F28EmailMessage(
            EmailProvider::Gmail,
            $mailboxReference,
            $id,
            (string) $historyId,
            $this->change($record),
            EmailAdapterValues::header($headers, 'message-id'),
            is_string($record['threadId'] ?? null) ? $record['threadId'] : null,
            EmailAdapterValues::header($headers, 'in-reply-to'),
            EmailAdapterValues::header($headers, 'subject'),
            $participants,
            $headers,
            $bodies,
            EmailAdapterValues::strings($record['labelIds'] ?? []),
            [],
            [],
            $attachments,
            EmailAdapterValues::date(EmailAdapterValues::header($headers, 'date')),
            $internalDate,
            $rawReference,
        );
    }

    /**
     * @param  array<string, mixed>  $part
     * @return array{0: list<EmailBody>, 1: list<EmailAttachment>}
     */
    private function parts(array $part, string $messageId): array
    {
        $bodies = [];
        $attachments = [];
        $mimeType = is_string($part['mimeType'] ?? null) ? $part['mimeType'] : 'application/octet-stream';
        $body = is_array($part['body'] ?? null) ? $part['body'] : [];
        $data = is_string($body['data'] ?? null) ? $this->decode($body['data']) : null;
        $attachmentId = $body['attachmentId'] ?? null;

        if ($data !== null && str_starts_with($mimeType, 'text/')) {
            $partHeaders = EmailAdapterValues::headers(is_array($part['headers'] ?? null) ? $part['headers'] : []);
            $bodies[] = new EmailBody($mimeType, $data, EmailAdapterValues::header($partHeaders, 'content-id'));
        }

        if (is_string($attachmentId) && $attachmentId !== '') {
            $attachments[] = new EmailAttachment(
                $attachmentId,
                is_string($part['filename'] ?? null) ? $part['filename'] : null,
                $mimeType,
                is_int($body['size'] ?? null) ? $body['size'] : null,
                'digory:email-attachment/gmail/'.$messageId.'/'.$attachmentId,
            );
        }

        foreach (is_array($part['parts'] ?? null) ? $part['parts'] : [] as $child) {
            if (is_array($child)) {
                [$childBodies, $childAttachments] = $this->parts($child, $messageId);
                array_push($bodies, ...$childBodies);
                array_push($attachments, ...$childAttachments);
            }
        }

        return [$bodies, $attachments];
    }

    private function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }

    /** @param array<string, mixed> $record */
    private function change(array $record): EmailChangeKind
    {
        if (($record['deleted'] ?? false) === true) {
            return EmailChangeKind::Deleted;
        }

        return is_string($record['change'] ?? null)
            ? EmailChangeKind::from($record['change'])
            : EmailChangeKind::Updated;
    }
}
