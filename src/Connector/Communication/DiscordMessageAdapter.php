<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Communication;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class DiscordMessageAdapter
{
    /** @param array<string, mixed> $event */
    public function adapt(array $event, string $sourceReference, string $rawReference): F28CommunicationRecord
    {
        $data = is_array($event['data'] ?? null) ? $event['data'] : $event;
        $eventType = is_string($event['type'] ?? null) ? $event['type'] : (is_string($event['t'] ?? null) ? $event['t'] : 'MESSAGE_CREATE');
        $eventId = $this->scalarString($event['event_id'] ?? $event['s'] ?? null);
        $providerId = $this->scalarString($data['id'] ?? $data['message_id'] ?? null);
        $guildId = $this->scalarString($data['guild_id'] ?? null);
        $channelId = $this->scalarString($data['channel_id'] ?? $data['id'] ?? null);
        $threadId = $this->scalarString($data['thread_id'] ?? null);
        $conversationId = $threadId ?? $channelId;
        $occurredAt = $this->date($data['timestamp'] ?? $data['edited_timestamp'] ?? $event['observed_at'] ?? null);

        if ($providerId === null || $conversationId === null || $occurredAt === null) {
            throw new InvalidArgumentException('Discord records require provider, conversation, and timestamp identities.');
        }

        $change = $this->change($eventType, $data);
        $revision = $eventId
            ?? $this->nullableString($data['edited_timestamp'] ?? null)
            ?? $eventType.':'.$providerId;
        $participants = $this->participants($data);

        return new F28CommunicationRecord(
            CommunicationProvider::Discord,
            $sourceReference,
            $conversationId,
            $threadId === null ? ($eventType === 'CHANNEL_DELETE' ? 'unavailable_channel' : 'channel') : 'thread',
            $providerId,
            $revision,
            $change,
            $occurredAt,
            $participants,
            is_string($data['content'] ?? null) ? $data['content'] : null,
            $this->nestedId($data, 'message_reference', 'message_id'),
            null,
            $threadId,
            $this->reactions($data),
            $this->attachments($data, $providerId),
            array_filter([
                'event_id' => $eventId,
                'event_type' => $eventType,
                'guild_id' => $guildId,
                'channel_id' => $channelId,
                'thread_id' => $threadId,
                'webhook_id' => $this->scalarString($data['webhook_id'] ?? null),
                'mentions' => $this->ids($data['mentions'] ?? null),
                'embeds' => $this->records($data['embeds'] ?? null),
                'object_kind' => $eventType === 'CHANNEL_DELETE' ? 'channel' : 'message',
            ], static fn (mixed $value): bool => $value !== null && $value !== []),
            $rawReference,
            $this->date($data['edited_timestamp'] ?? null),
            $change === CommunicationChange::Deleted || $change === CommunicationChange::Unavailable ? $occurredAt : null,
        );
    }

    /** @param array<string, mixed> $data
     * @return list<CommunicationParticipant>
     */
    private function participants(array $data): array
    {
        $participants = [];
        $author = is_array($data['author'] ?? null) ? $data['author'] : [];
        $authorId = $this->scalarString($author['id'] ?? $data['webhook_id'] ?? null);

        if ($authorId !== null) {
            $kind = array_key_exists('webhook_id', $data)
                ? 'webhook'
                : (($author['bot'] ?? false) === true ? 'bot' : 'user');
            $participants[] = new CommunicationParticipant(
                $authorId,
                'author',
                $kind,
                displayName: $this->nullableString($author['global_name'] ?? $author['username'] ?? null),
            );
        }

        foreach ($this->records($data['mentions'] ?? null) as $mention) {
            $id = $this->scalarString($mention['id'] ?? null);

            if ($id !== null) {
                $participants[] = new CommunicationParticipant(
                    $id,
                    'mentioned',
                    ($mention['bot'] ?? false) === true ? 'bot' : 'user',
                    displayName: $this->nullableString($mention['global_name'] ?? $mention['username'] ?? null),
                );
            }
        }

        return $participants;
    }

    /** @param array<string, mixed> $data
     * @return list<CommunicationReaction>
     */
    private function reactions(array $data): array
    {
        $reactions = [];

        foreach ($this->records($data['reactions'] ?? null) as $reaction) {
            $emoji = is_array($reaction['emoji'] ?? null) ? $reaction['emoji'] : [];
            $value = $this->nullableString($emoji['name'] ?? $reaction['emoji'] ?? null);

            if ($value !== null) {
                $reactions[] = new CommunicationReaction(
                    $value,
                    is_int($reaction['count'] ?? null) ? $reaction['count'] : 1,
                    $this->strings($reaction['participant_ids'] ?? []),
                );
            }
        }

        return $reactions;
    }

    /** @param array<string, mixed> $data
     * @return list<CommunicationAttachment>
     */
    private function attachments(array $data, string $messageId): array
    {
        $attachments = [];

        foreach ($this->records($data['attachments'] ?? null) as $attachment) {
            $id = $this->scalarString($attachment['id'] ?? null);

            if ($id !== null) {
                $attachments[] = new CommunicationAttachment(
                    $id,
                    'digory:discord-attachment/'.$messageId.'/'.$id,
                    $this->nullableString($attachment['filename'] ?? null),
                    $this->nullableString($attachment['content_type'] ?? null),
                    is_int($attachment['size'] ?? null) ? $attachment['size'] : null,
                    'discord',
                );
            }
        }

        return $attachments;
    }

    /** @param array<string, mixed> $data */
    private function change(string $eventType, array $data): CommunicationChange
    {
        return match ($eventType) {
            'MESSAGE_UPDATE' => CommunicationChange::Edited,
            'MESSAGE_DELETE', 'MESSAGE_DELETE_BULK' => CommunicationChange::Deleted,
            'CHANNEL_DELETE', 'THREAD_DELETE' => CommunicationChange::Unavailable,
            default => ($data['unavailable'] ?? false) === true ? CommunicationChange::Unavailable : CommunicationChange::Created,
        };
    }

    /** @param array<string, mixed> $record */
    private function nestedId(array $record, string $key, string $id): ?string
    {
        $value = is_array($record[$key] ?? null) ? $record[$key] : [];

        return $this->scalarString($value[$id] ?? null);
    }

    /** @return list<string> */
    private function ids(mixed $value): array
    {
        return array_values(array_filter(array_map(
            fn (array $record): ?string => $this->scalarString($record['id'] ?? null),
            $this->records($value),
        )));
    }

    /** @return list<array<string, mixed>> */
    private function records(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $record): bool => is_array($record)));
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        return is_array($value)
            ? array_values(array_filter($value, static fn (mixed $item): bool => is_string($item)))
            : [];
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
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
