<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Email;

use InvalidArgumentException;

final readonly class MicrosoftGraphMessageAdapter
{
    /** @param array<string, mixed> $record */
    public function adapt(array $record, string $mailboxReference, string $rawReference): F28EmailMessage
    {
        $id = $record['id'] ?? null;
        $revision = $record['changeKey'] ?? $record['lastModifiedDateTime'] ?? null;

        if (! is_string($id) || ! is_string($revision)) {
            throw new InvalidArgumentException('Microsoft Graph messages require id and changeKey values.');
        }

        $headerRows = [];

        foreach (is_array($record['internetMessageHeaders'] ?? null) ? $record['internetMessageHeaders'] : [] as $header) {
            if (is_array($header)) {
                $headerRows[] = ['name' => $header['name'] ?? null, 'value' => $header['value'] ?? null];
            }
        }

        $headers = EmailAdapterValues::headers($headerRows);
        $participants = [];
        $from = is_array($record['from'] ?? null) ? EmailAdapterValues::participant('from', $record['from']) : null;

        if ($from !== null) {
            $participants[] = $from;
        }

        foreach (['toRecipients' => 'to', 'ccRecipients' => 'cc', 'bccRecipients' => 'bcc', 'replyTo' => 'reply-to'] as $key => $role) {
            foreach (is_array($record[$key] ?? null) ? $record[$key] : [] as $value) {
                $participant = is_array($value) ? EmailAdapterValues::participant($role, $value) : null;

                if ($participant !== null) {
                    $participants[] = $participant;
                }
            }
        }

        $bodies = [];
        $body = is_array($record['body'] ?? null) ? $record['body'] : [];

        if (is_string($body['content'] ?? null)) {
            $bodies[] = new EmailBody(
                strtolower((string) ($body['contentType'] ?? 'text')) === 'html' ? 'text/html' : 'text/plain',
                $body['content'],
            );
        }

        $attachments = [];

        foreach (is_array($record['attachments'] ?? null) ? $record['attachments'] : [] as $attachment) {
            if (! is_array($attachment) || ! is_string($attachment['id'] ?? null)) {
                continue;
            }

            $attachments[] = new EmailAttachment(
                $attachment['id'],
                is_string($attachment['name'] ?? null) ? $attachment['name'] : null,
                is_string($attachment['contentType'] ?? null) ? $attachment['contentType'] : null,
                is_int($attachment['size'] ?? null) ? $attachment['size'] : null,
                'digory:email-attachment/microsoft-graph/'.$id.'/'.$attachment['id'],
            );
        }

        return new F28EmailMessage(
            EmailProvider::MicrosoftGraph,
            $mailboxReference,
            $id,
            $revision,
            $this->change($record),
            is_string($record['internetMessageId'] ?? null) ? $record['internetMessageId'] : EmailAdapterValues::header($headers, 'message-id'),
            is_string($record['conversationId'] ?? null) ? $record['conversationId'] : null,
            EmailAdapterValues::header($headers, 'in-reply-to'),
            is_string($record['subject'] ?? null) ? $record['subject'] : null,
            $participants,
            $headers,
            $bodies,
            EmailAdapterValues::strings($record['categories'] ?? []),
            is_string($record['parentFolderId'] ?? null) ? [$record['parentFolderId']] : [],
            array_filter([
                ($record['isRead'] ?? false) ? 'seen' : 'unseen',
                ($record['isDraft'] ?? false) ? 'draft' : null,
            ], is_string(...)),
            $attachments,
            EmailAdapterValues::date($record['sentDateTime'] ?? null),
            EmailAdapterValues::date($record['receivedDateTime'] ?? null),
            $rawReference,
        );
    }

    /** @param array<string, mixed> $record */
    private function change(array $record): EmailChangeKind
    {
        if (($record['@removed']['reason'] ?? null) !== null) {
            return EmailChangeKind::Deleted;
        }

        return is_string($record['change'] ?? null)
            ? EmailChangeKind::from($record['change'])
            : EmailChangeKind::Updated;
    }
}
