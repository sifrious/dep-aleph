<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Email;

use InvalidArgumentException;

final readonly class ImapMessageAdapter
{
    /** @param array<string, mixed> $record */
    public function adapt(array $record, string $mailboxReference, string $rawReference): F28EmailMessage
    {
        $uid = $record['uid'] ?? null;
        $uidValidity = $record['uidvalidity'] ?? null;

        if ((! is_int($uid) && ! is_string($uid)) || (! is_int($uidValidity) && ! is_string($uidValidity))) {
            throw new InvalidArgumentException('IMAP messages require UID and UIDVALIDITY values.');
        }

        $providerId = $uidValidity.':'.$uid;
        $headers = is_array($record['headers'] ?? null) ? $this->headers($record['headers']) : [];
        $participants = [];

        foreach (['from', 'to', 'cc', 'bcc', 'reply-to'] as $role) {
            array_push($participants, ...EmailAdapterValues::participants($role, EmailAdapterValues::header($headers, $role)));
        }

        $bodies = [];

        foreach (is_array($record['bodies'] ?? null) ? $record['bodies'] : [] as $body) {
            if (is_array($body) && is_string($body['content'] ?? null)) {
                $bodies[] = new EmailBody(
                    is_string($body['media_type'] ?? null) ? $body['media_type'] : 'text/plain',
                    $body['content'],
                    is_string($body['content_id'] ?? null) ? $body['content_id'] : null,
                );
            }
        }

        $attachments = [];

        foreach (is_array($record['attachments'] ?? null) ? $record['attachments'] : [] as $attachment) {
            if (! is_array($attachment) || ! is_string($attachment['part_id'] ?? null)) {
                continue;
            }

            $attachments[] = new EmailAttachment(
                $attachment['part_id'],
                is_string($attachment['filename'] ?? null) ? $attachment['filename'] : null,
                is_string($attachment['media_type'] ?? null) ? $attachment['media_type'] : null,
                is_int($attachment['size'] ?? null) ? $attachment['size'] : null,
                'digory:email-attachment/imap/'.$providerId.'/'.$attachment['part_id'],
            );
        }

        return new F28EmailMessage(
            EmailProvider::Imap,
            $mailboxReference,
            $providerId,
            $providerId.':'.(string) ($record['modseq'] ?? '0'),
            $this->change($record),
            EmailAdapterValues::header($headers, 'message-id'),
            is_string($record['thread_id'] ?? null) ? $record['thread_id'] : null,
            EmailAdapterValues::header($headers, 'in-reply-to'),
            EmailAdapterValues::header($headers, 'subject'),
            $participants,
            $headers,
            $bodies,
            [],
            EmailAdapterValues::strings($record['folders'] ?? []),
            EmailAdapterValues::strings($record['flags'] ?? []),
            $attachments,
            EmailAdapterValues::date(EmailAdapterValues::header($headers, 'date')),
            EmailAdapterValues::date($record['internal_date'] ?? null),
            $rawReference,
        );
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, list<string>>
     */
    private function headers(array $headers): array
    {
        $rows = [];

        foreach ($headers as $name => $values) {
            foreach (is_array($values) ? $values : [$values] as $value) {
                $rows[] = ['name' => $name, 'value' => $value];
            }
        }

        return EmailAdapterValues::headers($rows);
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
