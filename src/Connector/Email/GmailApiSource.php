<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Email;

use InvalidArgumentException;

final readonly class GmailApiSource implements EmailSource
{
    public function __construct(
        private string $reference,
        private string $mailbox,
        private GmailApiTransport $transport,
        private bool $includeSpamTrash = false,
    ) {
        if (trim($reference) === '' || trim($mailbox) === '') {
            throw new InvalidArgumentException('Gmail source requires mailbox and source references.');
        }
    }

    public function sourceReference(): string
    {
        return $this->reference;
    }

    public function checkpointType(): string
    {
        return 'gmail.sync-state';
    }

    public function page(?string $checkpoint, int $limit): EmailPage
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Gmail page size must be between 1 and 500.');
        }

        $state = GmailCheckpoint::decode($checkpoint);

        return $state->mode === 'history'
            ? $this->historyPage($state, $limit)
            : $this->fullPage($state, $limit);
    }

    private function fullPage(GmailCheckpoint $state, int $limit): EmailPage
    {
        $query = ['maxResults' => $limit, 'includeSpamTrash' => $this->includeSpamTrash];

        if ($state->pageToken !== null) {
            $query['pageToken'] = $state->pageToken;
        }

        $response = $this->transport->get($this->userPath().'/messages', $query);
        $messages = [];
        $historyId = $state->historyId;

        foreach ($this->records($response, 'messages') as $record) {
            $id = $record['id'] ?? null;

            if (! is_string($id) || $id === '') {
                throw new InvalidArgumentException('Gmail message listing returned an invalid message ID.');
            }

            $message = $this->message($id, EmailChangeKind::Created);
            $historyId ??= $message->providerRevision;
            $messages[] = $message;
        }

        $nextPage = $this->optionalString($response, 'nextPageToken');

        if ($nextPage !== null) {
            return new EmailPage($messages, GmailCheckpoint::full($historyId, $nextPage)->encode(), true);
        }

        $historyId ??= $this->profileHistoryId();

        return new EmailPage($messages, GmailCheckpoint::history($historyId)->encode(), false);
    }

    private function historyPage(GmailCheckpoint $state, int $limit): EmailPage
    {
        $query = ['startHistoryId' => (string) $state->historyId, 'maxResults' => $limit];

        if ($state->pageToken !== null) {
            $query['pageToken'] = $state->pageToken;
        }

        $response = $this->transport->get($this->userPath().'/history', $query);
        $changes = [];

        foreach ($this->records($response, 'history') as $history) {
            $revision = $history['id'] ?? null;

            if (! is_string($revision) || $revision === '') {
                throw new InvalidArgumentException('Gmail history returned an invalid history ID.');
            }

            $this->collectChanges($changes, $history, 'messagesAdded', EmailChangeKind::Created, $revision);
            $this->collectChanges($changes, $history, 'labelsAdded', EmailChangeKind::Updated, $revision);
            $this->collectChanges($changes, $history, 'labelsRemoved', EmailChangeKind::Updated, $revision);
            $this->collectChanges($changes, $history, 'messagesDeleted', EmailChangeKind::Deleted, $revision);
        }

        $messages = [];

        foreach ($changes as $id => [$kind, $revision]) {
            $messages[] = $kind === EmailChangeKind::Deleted
                ? $this->deletedMessage($id, $revision)
                : $this->message($id, $kind);
        }

        $nextPage = $this->optionalString($response, 'nextPageToken');

        if ($nextPage !== null) {
            return new EmailPage($messages, GmailCheckpoint::history((string) $state->historyId, $nextPage)->encode(), true);
        }

        $historyId = $this->requiredString($response, 'historyId', 'Gmail history response');

        return new EmailPage($messages, GmailCheckpoint::history($historyId)->encode(), false);
    }

    /**
     * @param  array<string, array{EmailChangeKind, string}>  $changes
     * @param  array<string, mixed>  $history
     */
    private function collectChanges(array &$changes, array $history, string $field, EmailChangeKind $kind, string $revision): void
    {
        foreach ($this->records($history, $field) as $change) {
            $message = is_array($change['message'] ?? null) ? $change['message'] : [];
            $id = $message['id'] ?? null;

            if (! is_string($id) || $id === '') {
                throw new InvalidArgumentException("Gmail history [{$field}] returned an invalid message ID.");
            }

            $changes[$id] = [$kind, $revision];
        }
    }

    private function message(string $id, EmailChangeKind $kind): F28EmailMessage
    {
        $record = $this->transport->get($this->userPath().'/messages/'.rawurlencode($id), ['format' => 'full']);
        $record['change'] = $kind->value;

        return (new GmailMessageAdapter)->adapt(
            $record,
            $this->reference,
            'gmail:message/'.$id.'/'.(string) ($record['historyId'] ?? 'unknown'),
        );
    }

    private function deletedMessage(string $id, string $revision): F28EmailMessage
    {
        return (new GmailMessageAdapter)->adapt([
            'id' => $id,
            'historyId' => $revision,
            'deleted' => true,
            'payload' => [],
        ], $this->reference, 'gmail:message/'.$id.'/'.$revision);
    }

    private function profileHistoryId(): string
    {
        return $this->requiredString(
            $this->transport->get($this->userPath().'/profile'),
            'historyId',
            'Gmail profile',
        );
    }

    private function userPath(): string
    {
        return 'users/'.rawurlencode($this->mailbox);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<array<string, mixed>>
     */
    private function records(array $record, string $field): array
    {
        $values = $record[$field] ?? [];

        if (! is_array($values)) {
            throw new InvalidArgumentException("Gmail response field [{$field}] must be a list.");
        }

        $records = [];

        foreach ($values as $value) {
            if (! is_array($value)) {
                throw new InvalidArgumentException("Gmail response field [{$field}] contains an invalid record.");
            }

            $records[] = $value;
        }

        return $records;
    }

    /** @param array<string, mixed> $record */
    private function optionalString(array $record, string $field): ?string
    {
        $value = $record[$field] ?? null;

        if ($value !== null && (! is_string($value) || $value === '')) {
            throw new InvalidArgumentException("Gmail response field [{$field}] must be a non-empty string.");
        }

        return $value;
    }

    /** @param array<string, mixed> $record */
    private function requiredString(array $record, string $field, string $subject): string
    {
        $value = $record[$field] ?? null;

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("{$subject} requires [{$field}].");
        }

        return $value;
    }
}
