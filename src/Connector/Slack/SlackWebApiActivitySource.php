<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class SlackWebApiActivitySource implements SlackActivitySource
{
    public function __construct(
        private string $workspaceReference,
        private string $sourceInstallationId,
        private SlackCredentialBroker $credentials,
        private SlackWebApiTransport $transport,
    ) {}

    public function sourceReference(): string
    {
        return $this->workspaceReference;
    }

    public function page(string $partition, SlackCheckpoint $checkpoint, int $limit): SlackActivityPage
    {
        $token = $this->credentials->accessToken($this->sourceInstallationId, new DateTimeImmutable);

        return match (true) {
            $partition === 'users' => $this->users($token, $checkpoint, $limit),
            $partition === 'channels' => $this->channels($token, $checkpoint, $limit),
            str_starts_with($partition, 'history:') => $this->history(substr($partition, 8), $token, $checkpoint, $limit),
            default => throw new InvalidArgumentException("Unknown Slack partition [{$partition}]."),
        };
    }

    private function users(SlackTokenSecret $token, SlackCheckpoint $checkpoint, int $limit): SlackActivityPage
    {
        $payload = $this->transport->get('users.list', $token, $this->query($checkpoint, $limit));

        return $this->pageOf($payload, 'members', $checkpoint, fn (array $record): SlackActivity => $this->user($record));
    }

    private function channels(SlackTokenSecret $token, SlackCheckpoint $checkpoint, int $limit): SlackActivityPage
    {
        $payload = $this->transport->get('conversations.list', $token, [
            ...$this->query($checkpoint, $limit),
            'exclude_archived' => 'false',
            'types' => 'public_channel,private_channel,mpim,im',
        ]);

        return $this->pageOf($payload, 'channels', $checkpoint, fn (array $record): SlackActivity => $this->channel($record));
    }

    private function history(string $channel, SlackTokenSecret $token, SlackCheckpoint $checkpoint, int $limit): SlackActivityPage
    {
        if ($channel === '') {
            throw new InvalidArgumentException('Slack history partitions require a channel identifier.');
        }

        $query = [...$this->query($checkpoint, $limit), 'channel' => $channel, 'inclusive' => 'false'];

        if ($checkpoint->highWater !== null) {
            $query['oldest'] = $checkpoint->highWater;
        }

        $payload = $this->transport->get('conversations.history', $token, $query);

        return $this->pageOf(
            $payload,
            'messages',
            $checkpoint,
            fn (array $record): SlackActivity => $this->message($channel, $record),
            $this->latestTimestamp($payload['messages'] ?? [], $checkpoint->highWater),
        );
    }

    /** @return array<string, scalar> */
    private function query(SlackCheckpoint $checkpoint, int $limit): array
    {
        return array_filter([
            'limit' => $limit,
            'cursor' => $checkpoint->cursor,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(array<string, mixed>): SlackActivity  $map
     */
    private function pageOf(array $payload, string $key, SlackCheckpoint $checkpoint, callable $map, ?string $highWater = null): SlackActivityPage
    {
        $records = $payload[$key] ?? [];

        if (! is_array($records)) {
            throw new InvalidArgumentException("Slack response field [{$key}] must be a list.");
        }

        $activities = [];

        foreach ($records as $record) {
            if (is_array($record)) {
                $activities[] = $map($record);
            }
        }

        $metadata = $payload['response_metadata'] ?? [];
        $cursor = is_array($metadata) && is_string($metadata['next_cursor'] ?? null)
            ? trim($metadata['next_cursor'])
            : '';

        return new SlackActivityPage(
            $activities,
            $cursor === '' ? null : $cursor,
            $highWater ?? $checkpoint->highWater,
            $cursor !== '',
        );
    }

    /** @param array<string, mixed> $record */
    private function user(array $record): SlackActivity
    {
        $id = $this->required($record, 'id');
        $revision = is_int($record['updated'] ?? null) ? (string) $record['updated'] : $id;

        return new SlackActivity(
            SlackActivityKind::User,
            $this->workspaceReference,
            $id,
            $revision,
            $this->time($revision),
            $record,
            rawReference: 'slack:raw/user/'.$id,
        );
    }

    /** @param array<string, mixed> $record */
    private function channel(array $record): SlackActivity
    {
        $id = $this->required($record, 'id');
        $created = is_int($record['created'] ?? null) ? (string) $record['created'] : '0';

        return new SlackActivity(
            SlackActivityKind::Channel,
            $this->workspaceReference,
            $id,
            $created,
            $this->time($created),
            $record,
            channelReference: 'slack:channel/'.$id,
            rawReference: 'slack:raw/channel/'.$id,
        );
    }

    /** @param array<string, mixed> $record */
    private function message(string $channel, array $record): SlackActivity
    {
        $timestamp = $this->required($record, 'ts');
        $id = is_string($record['client_msg_id'] ?? null) && $record['client_msg_id'] !== ''
            ? $record['client_msg_id']
            : $timestamp;
        $edited = $record['edited'] ?? [];
        $revision = is_array($edited) && is_string($edited['ts'] ?? null) ? $edited['ts'] : $timestamp;
        $relationships = [];

        if (is_string($record['thread_ts'] ?? null) && $record['thread_ts'] !== $timestamp) {
            $relationships['thread'] = 'slack:message/'.$channel.'/'.$record['thread_ts'];
        }

        foreach (is_array($record['files'] ?? null) ? $record['files'] : [] as $file) {
            if (is_array($file) && is_string($file['id'] ?? null)) {
                $relationships['file:'.$file['id']] = 'slack:file/'.$file['id'];
            }
        }

        return new SlackActivity(
            SlackActivityKind::Message,
            $this->workspaceReference,
            $id,
            $revision,
            $this->time($timestamp),
            $record,
            $relationships,
            'slack:channel/'.$channel,
            'slack:raw/'.$channel.'/'.$timestamp,
        );
    }

    /** @param array<string, mixed> $record */
    private function required(array $record, string $key): string
    {
        $value = $record[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("Slack record field [{$key}] is required.");
        }

        return $value;
    }

    private function time(string $timestamp): DateTimeImmutable
    {
        $seconds = (int) explode('.', $timestamp)[0];

        return new DateTimeImmutable('@'.max(0, $seconds));
    }

    private function latestTimestamp(mixed $records, ?string $current): ?string
    {
        $latest = $current;

        foreach (is_array($records) ? $records : [] as $record) {
            $timestamp = is_array($record) && is_string($record['ts'] ?? null) ? $record['ts'] : null;

            if ($timestamp !== null && ($latest === null || version_compare($timestamp, $latest, '>'))) {
                $latest = $timestamp;
            }
        }

        return $latest;
    }
}
