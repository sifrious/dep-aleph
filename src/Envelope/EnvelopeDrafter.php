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
        $metadata = $envelope->metadata();
        $metadata['aleph']['source_scopes'] = $this->scopes->snapshot(
            $envelope->provenance->installationId,
            $envelope->stream,
        );

        return new ObservationDraft(
            sourceReference: $envelope->sourceReference,
            sourceName: $envelope->sourceName,
            resourceReference: $envelope->resourceReference,
            producerReference: 'aleph:connector/'.$envelope->provenance->connectorId,
            producerName: $envelope->provenance->connectorId,
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
            metadata: $metadata,
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
