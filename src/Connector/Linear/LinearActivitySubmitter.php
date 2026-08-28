<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Linear;

use DateTimeImmutable;
use InvalidArgumentException;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ExtensionMetadata;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;

final readonly class LinearActivitySubmitter
{
    public function __construct(private EnvelopeSubmitter $submitter) {}

    public function submit(
        LinearActivity $activity,
        string $installationId,
        ?string $account,
        DateTimeImmutable $capturedAt,
        string $transport,
        ?string $runId = null,
        ?string $attemptId = null,
        ?string $deliveryId = null,
    ): string {
        $outcome = $this->submitter->submit(new ObservationEnvelope(
            sourceReference: $activity->workspaceReference,
            sourceName: $activity->workspaceReference,
            resourceReference: $activity->resourceReference(),
            observedAt: $capturedAt,
            payload: $activity->contents(),
            provenance: new Provenance('linear-activity', '1.0.0', $installationId, $capturedAt, $runId, [
                'transport' => $transport,
                'delivery_id' => $deliveryId,
                'provider_url' => is_string($activity->payload['url'] ?? null) ? $activity->payload['url'] : null,
            ]),
            contentType: 'application/json',
            account: $account,
            stream: $activity->kind->value,
            eventType: 'linear.'.$activity->kind->value,
            providerId: $activity->providerId,
            providerRevision: $activity->revision(),
            extensions: [new ExtensionMetadata('linear.activity', 1, [
                'workspace' => $activity->workspaceReference,
                'transport' => $transport,
                'attachment_references' => array_map(
                    static fn (LinearAttachmentReference $attachment): array => $attachment->toArray(),
                    $activity->attachments,
                ),
            ])],
            occurredAt: $activity->updatedAt,
        ), $attemptId);
        $accepted = $outcome->acceptedId();

        if (! $outcome->isAuthoritative() || $accepted === null) {
            throw new InvalidArgumentException('Funes did not accept the Linear activity.');
        }

        return $accepted;
    }
}
