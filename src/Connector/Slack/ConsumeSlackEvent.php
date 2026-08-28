<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

use DateTimeImmutable;
use InvalidArgumentException;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\Values\WebhookDelivery;

final readonly class ConsumeSlackEvent
{
    public function __construct(private SlackEventSecrets $secrets, private ConnectorInstallations $installations, private SlackActivitySubmitter $submitter) {}

    /** @return list<string> */
    public function consume(WebhookDelivery $delivery): array
    {
        $timestamp = $delivery->header('X-Slack-Request-Timestamp');
        $signature = $delivery->header('X-Slack-Signature');
        $secret = $this->secrets->get($delivery->sourceReference);

        if ($timestamp === null || $signature === null || ! hash_equals('v0='.hash_hmac('sha256', 'v0:'.$timestamp.':'.$delivery->body, $secret), $signature)) {
            throw new InvalidArgumentException('Slack event signature is invalid.');
        }

        $installation = $this->installations->find($delivery->sourceReference);
        $decoded = json_decode($delivery->body, true, 512, JSON_THROW_ON_ERROR);
        $event = is_array($decoded['event'] ?? null) ? $decoded['event'] : [];
        $workspace = $installation?->externalAccountId;
        $ts = (string) ($event['event_ts'] ?? $event['ts'] ?? $decoded['event_id'] ?? '');

        if ($installation === null || $workspace === null || $ts === '') {
            throw new InvalidArgumentException('Slack event requires installation, workspace, and event identity.');
        }

        $kind = isset($event['file']) ? SlackActivityKind::File : SlackActivityKind::Message;
        $providerId = $kind === SlackActivityKind::File ? (string) ($event['file']['id'] ?? $ts) : (string) ($event['client_msg_id'] ?? $event['ts'] ?? $ts);
        $payload = array_intersect_key($event, array_fill_keys([
            'ts', 'client_msg_id', 'user', 'text', 'files', 'thread_ts', 'subtype', 'bot_id', 'app_id', 'edited', 'reactions',
        ], true));
        $activity = new SlackActivity($kind, $workspace, $providerId, (string) ($event['edited']['ts'] ?? $ts), new DateTimeImmutable('@'.(int) $ts), $payload, array_filter(['thread' => is_string($event['thread_ts'] ?? null) ? 'slack:message/'.$event['thread_ts'] : null], is_string(...)), is_string($event['channel'] ?? null) ? 'slack:channel/'.$event['channel'] : null, 'slack:event/'.(string) ($decoded['event_id'] ?? $ts));

        return [$this->submitter->submit($activity, $installation->id, $installation->externalAccountId, 'webhook')];
    }
}
