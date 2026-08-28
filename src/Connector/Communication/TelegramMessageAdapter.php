<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Communication;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class TelegramMessageAdapter
{
    /** @param array<string, mixed> $update */
    public function adapt(array $update, string $sourceReference, string $rawReference): F28CommunicationRecord
    {
        $updateId = $this->scalarString($update['update_id'] ?? null);

        if ($updateId === null) {
            throw new InvalidArgumentException('Telegram updates require an update_id.');
        }

        [$message, $change] = $this->message($update);
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $chatId = $this->scalarString($chat['id'] ?? null) ?? 'update-stream';
        $messageId = $this->scalarString($message['message_id'] ?? null) ?? 'update-'.$updateId;
        $occurredAt = $this->date($message['date'] ?? null) ?? new DateTimeImmutable('@0');
        $editedAt = $this->date($message['edit_date'] ?? null);
        $deletedAt = $change === CommunicationChange::Deleted ? $this->date($message['delete_date'] ?? null) ?? $occurredAt : null;
        $participants = [];
        $sender = is_array($message['from'] ?? null) ? $message['from'] : [];
        $senderId = $this->scalarString($sender['id'] ?? null);

        if ($senderId !== null) {
            $participants[] = new CommunicationParticipant(
                $senderId,
                'sender',
                ($sender['is_bot'] ?? false) === true ? 'bot' : 'user',
                displayName: $this->displayName($sender),
            );
        }

        $participants[] = new CommunicationParticipant(
            $chatId,
            'conversation',
            is_string($chat['type'] ?? null) ? $chat['type'] : 'unknown',
            displayName: $this->nullableString($chat['title'] ?? $chat['username'] ?? null),
        );

        return new F28CommunicationRecord(
            CommunicationProvider::Telegram,
            $sourceReference,
            $chatId,
            is_string($chat['type'] ?? null) ? $chat['type'] : 'unknown',
            $messageId,
            $updateId,
            $change,
            $occurredAt,
            $participants,
            $this->nullableString($message['text'] ?? $message['caption'] ?? null),
            $this->nestedId($message, 'reply_to_message', 'message_id'),
            $this->forwardedFrom($message),
            $this->scalarString($message['message_thread_id'] ?? null),
            $this->reactions($message),
            $this->attachments($message, $chatId, $messageId),
            ['update_id' => $updateId, 'provider_event' => $this->eventName($update)],
            $rawReference,
            $editedAt,
            $deletedAt,
        );
    }

    /** @param array<string, mixed> $update
     * @return array{0: array<string, mixed>, 1: CommunicationChange}
     */
    private function message(array $update): array
    {
        foreach ([
            'edited_message' => CommunicationChange::Edited,
            'edited_channel_post' => CommunicationChange::Edited,
            'deleted_message' => CommunicationChange::Deleted,
            'message' => CommunicationChange::Created,
            'channel_post' => CommunicationChange::Created,
        ] as $key => $change) {
            if (is_array($update[$key] ?? null)) {
                return [$update[$key], $change];
            }
        }

        return [$update, CommunicationChange::Unsupported];
    }

    /** @param array<string, mixed> $update */
    private function eventName(array $update): string
    {
        foreach (['edited_message', 'edited_channel_post', 'deleted_message', 'message', 'channel_post'] as $key) {
            if (array_key_exists($key, $update)) {
                return $key;
            }
        }

        return 'unsupported';
    }

    /** @param array<string, mixed> $message
     * @return list<CommunicationAttachment>
     */
    private function attachments(array $message, string $chatId, string $messageId): array
    {
        $attachments = [];

        foreach (['document', 'video', 'audio', 'voice', 'animation', 'sticker'] as $kind) {
            $value = is_array($message[$kind] ?? null) ? $message[$kind] : null;
            $fileId = $value === null ? null : $this->scalarString($value['file_id'] ?? null);

            if ($value !== null && $fileId !== null) {
                $attachments[] = $this->attachment($value, $kind, $fileId, $chatId, $messageId);
            }
        }

        $photos = is_array($message['photo'] ?? null) ? $message['photo'] : [];
        $photo = $photos === [] ? null : end($photos);

        if (is_array($photo) && ($fileId = $this->scalarString($photo['file_id'] ?? null)) !== null) {
            $attachments[] = $this->attachment($photo, 'photo', $fileId, $chatId, $messageId);
        }

        return $attachments;
    }

    /** @param array<string, mixed> $value */
    private function attachment(array $value, string $kind, string $fileId, string $chatId, string $messageId): CommunicationAttachment
    {
        return new CommunicationAttachment(
            $fileId,
            'digory:telegram-attachment/'.$chatId.'/'.$messageId.'/'.$fileId,
            $this->nullableString($value['file_name'] ?? null),
            $this->nullableString($value['mime_type'] ?? null),
            is_int($value['file_size'] ?? null) ? $value['file_size'] : null,
            $kind,
        );
    }

    /** @param array<string, mixed> $message
     * @return list<CommunicationReaction>
     */
    private function reactions(array $message): array
    {
        $reactions = [];

        foreach (is_array($message['reactions'] ?? null) ? $message['reactions'] : [] as $reaction) {
            if (! is_array($reaction)) {
                continue;
            }

            $value = $this->nullableString($reaction['emoji'] ?? $reaction['type'] ?? null);

            if ($value !== null) {
                $reactions[] = new CommunicationReaction($value, is_int($reaction['count'] ?? null) ? $reaction['count'] : 1);
            }
        }

        return $reactions;
    }

    /** @param array<string, mixed> $message */
    private function forwardedFrom(array $message): ?string
    {
        foreach (['forward_origin', 'forward_from', 'forward_from_chat'] as $key) {
            $origin = is_array($message[$key] ?? null) ? $message[$key] : null;
            $id = $origin === null ? null : $this->scalarString($origin['id'] ?? $origin['message_id'] ?? null);

            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $record */
    private function nestedId(array $record, string $key, string $id): ?string
    {
        $value = is_array($record[$key] ?? null) ? $record[$key] : [];

        return $this->scalarString($value[$id] ?? null);
    }

    /** @param array<string, mixed> $person */
    private function displayName(array $person): ?string
    {
        $name = trim(implode(' ', array_filter([
            $this->nullableString($person['first_name'] ?? null),
            $this->nullableString($person['last_name'] ?? null),
        ])));

        return $name !== '' ? $name : $this->nullableString($person['username'] ?? null);
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (new DateTimeImmutable)->setTimestamp((int) $value);
        }

        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }

    private function scalarString(mixed $value): ?string
    {
        return is_string($value) || is_int($value) ? (string) $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
