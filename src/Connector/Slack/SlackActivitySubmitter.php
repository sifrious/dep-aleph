<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

use DateTimeImmutable;
use InvalidArgumentException;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ExtensionMetadata;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;

final readonly class SlackActivitySubmitter
{
    public function __construct(private EnvelopeSubmitter $submitter) {}

    public function submit(SlackActivity $activity, string $installationId, ?string $account, string $transport, ?string $runId = null, ?string $attemptId = null): string
    {
        $capturedAt = new DateTimeImmutable;
        $outcome = $this->submitter->submit(new ObservationEnvelope(
            sourceReference: $activity->workspaceReference,
            sourceName: $activity->workspaceReference,
            resourceReference: $activity->resourceReference(),
            observedAt: $capturedAt,
            payload: json_encode($activity->contents(), JSON_THROW_ON_ERROR),
            provenance: new Provenance('slack', '1.0.0', $installationId, $capturedAt, $runId, ['transport' => $transport, 'raw_reference' => $activity->rawReference]),
            contentType: 'application/json',
            account: $account,
            stream: $activity->channelReference,
            eventType: 'slack.'.$activity->kind->value,
            providerId: $activity->providerId,
            providerRevision: $activity->revision,
            extensions: [new ExtensionMetadata('slack.activity', 1, ['relationships' => $activity->relationships, 'transport' => $transport])],
            occurredAt: $activity->occurredAt,
        ), $attemptId);

        if (! $outcome->isAuthoritative() || $outcome->acceptedId() === null) {
            throw new InvalidArgumentException('Funes did not accept the Slack activity.');
        }

        return $outcome->acceptedId();
    }
}
