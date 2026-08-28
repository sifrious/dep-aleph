<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Envelope;

use Sifrious\Aleph\Scope\SourceScopeAssociations;
use Sifrious\Funes\Value\Discovery;
use Sifrious\Funes\Value\ObservationDraft;

final readonly class EnvelopeDrafter
{
    public function __construct(private SourceScopeAssociations $scopes) {}

    public function draft(ObservationEnvelope $envelope): ObservationDraft
    {
        return new ObservationDraft(
            sourceReference: $envelope->sourceReference,
            sourceName: $envelope->sourceName,
            resourceReference: $envelope->resourceReference,
            producerReference: 'aleph:connector/'.$envelope->provenance->connectorId,
            producerName: $envelope->provenance->connectorId,
            ingestionRunReference: $envelope->provenance->runId
                ?? 'aleph:run/direct-'.hash('sha256', implode('|', [
                    $envelope->provenance->connectorId,
                    $envelope->provenance->installationId,
                    $envelope->sourceReference,
                    $envelope->resourceReference,
                    $envelope->observedAt->format(DATE_ATOM),
                ])),
            observedAt: $envelope->observedAt,
            payload: $envelope->payload,
            occurredAt: $envelope->occurredAt,
            transformationLineage: $envelope->normalization === null
                ? []
                : [
                    $envelope->normalization->normalizer->reference(),
                    $envelope->normalization->schema->reference(),
                ],
            contentType: $envelope->contentType,
            metadata: ObservationMetadata::drafts(
                $envelope,
                $this->scopes->snapshot(
                    $envelope->provenance->installationId,
                    $envelope->stream,
                ),
            ),
            discoveries: array_map(
                static fn (DiscoveryReference $discovery): Discovery => new Discovery(
                    $discovery->reference,
                    $discovery->relationship,
                ),
                $envelope->discoveries,
            ),
        );
    }
}
