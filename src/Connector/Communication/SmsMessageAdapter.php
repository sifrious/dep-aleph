<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Communication;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class SmsMessageAdapter
{
    /** @param array<string, mixed> $record */
    public function adapt(array $record, string $sourceReference, string $rawReference): F28CommunicationRecord
    {
        $messageId = $this->scalarString($record['message_id'] ?? $record['id'] ?? null);
        $conversationId = $this->scalarString($record['conversation_id'] ?? $record['thread_id'] ?? null);
        $revision = $this->scalarString($record['revision'] ?? $record['event_id'] ?? $messageId);
        $occurredAt = $this->date($record['occurred_at'] ?? $record['received_at'] ?? $record['sent_at'] ?? null);

        if ($messageId === null || $conversationId === null || $revision === null || $occurredAt === null) {
            throw new InvalidArgumentException('SMS records require message, conversation, revision, and timestamp values.');
        }

        $direction = is_string($record['direction'] ?? null) && in_array($record['direction'], ['inbound', 'outbound'], true)
            ? $record['direction']
            : throw new InvalidArgumentException('SMS records require inbound or outbound direction.');
        $participants = [];

        foreach ($this->addresses($record['from'] ?? null) as $address) {
            $participants[] = $this->participant($address, $direction === 'inbound' ? 'sender' : 'local');
        }

        foreach ($this->addresses($record['to'] ?? null) as $address) {
            $participants[] = $this->participant($address, $direction === 'outbound' ? 'recipient' : 'local');
        }

        foreach ($this->addresses($record['participants'] ?? null) as $address) {
            if (! in_array($address, array_map(static fn (CommunicationParticipant $participant): ?string => $participant->originalAddress, $participants), true)) {
                $participants[] = $this->participant($address, 'participant');
            }
        }

        return new F28CommunicationRecord(
            CommunicationProvider::Sms,
            $sourceReference,
            $conversationId,
            is_string($record['conversation_kind'] ?? null) ? $record['conversation_kind'] : (count($participants) > 2 ? 'group_mms' : 'sms'),
            $messageId,
            $revision,
            $this->change($record),
            $occurredAt,
            $participants,
            is_string($record['body'] ?? null) ? $record['body'] : null,
            $this->scalarString($record['reply_to'] ?? null),
            null,
            $conversationId,
            [],
            $this->attachments($record, $messageId),
            array_filter([
                'direction' => $direction,
                'delivery_state' => is_string($record['delivery_state'] ?? null) ? $record['delivery_state'] : null,
                'read_state' => is_string($record['read_state'] ?? null) ? $record['read_state'] : null,
                'collector' => is_string($record['collector'] ?? null) ? $record['collector'] : null,
            ], static fn (mixed $value): bool => $value !== null),
            $rawReference,
            $this->date($record['edited_at'] ?? null),
            $this->date($record['deleted_at'] ?? null),
        );
    }

    private function participant(string $address, string $role): CommunicationParticipant
    {
        return new CommunicationParticipant(
            'address:'.hash('sha256', $address),
            $role,
            'source_address',
            $address,
            $this->normalizeAddress($address),
        );
    }

    public function normalizeAddress(string $address): string
    {
        $trimmed = trim($address);

        if (str_contains($trimmed, '@')) {
            return strtolower($trimmed);
        }

        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        return str_starts_with($trimmed, '+') && $digits !== '' ? '+'.$digits : $digits;
    }

    /** @param array<string, mixed> $record
     * @return list<CommunicationAttachment>
     */
    private function attachments(array $record, string $messageId): array
    {
        $attachments = [];

        foreach (is_array($record['attachments'] ?? null) ? $record['attachments'] : [] as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $id = $this->scalarString($attachment['id'] ?? $attachment['provider_id'] ?? null);

            if ($id !== null) {
                $attachments[] = new CommunicationAttachment(
                    $id,
                    'digory:sms-attachment/'.$messageId.'/'.$id,
                    is_string($attachment['filename'] ?? null) ? $attachment['filename'] : null,
                    is_string($attachment['media_type'] ?? null) ? $attachment['media_type'] : null,
                    is_int($attachment['size'] ?? null) ? $attachment['size'] : null,
                    'mms',
                );
            }
        }

        return $attachments;
    }

    /** @return list<string> */
    private function addresses(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $address): bool => is_string($address) && $address !== ''));
    }

    /** @param array<string, mixed> $record */
    private function change(array $record): CommunicationChange
    {
        if (is_string($record['change'] ?? null)) {
            return CommunicationChange::from($record['change']);
        }

        if (array_key_exists('deleted_at', $record)) {
            return CommunicationChange::Deleted;
        }

        if (array_key_exists('edited_at', $record)) {
            return CommunicationChange::Edited;
        }

        return CommunicationChange::Created;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $numeric = (int) $value;
            $seconds = $numeric > 9999999999 ? (int) floor($numeric / 1000) : $numeric;

            return (new DateTimeImmutable)->setTimestamp($seconds);
        }

        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }

    private function scalarString(mixed $value): ?string
    {
        return is_string($value) || is_int($value) ? (string) $value : null;
    }
}
