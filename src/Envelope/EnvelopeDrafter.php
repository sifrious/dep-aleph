<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Envelope;

use Sifrious\Funes\Value\Discovery;
use Sifrious\Funes\Value\ObservationDraft;

final readonly class EnvelopeDrafter
{
    public function draft(ObservationEnvelope $envelope): ObservationDraft
    {
        return new ObservationDraft(
            sourceReference: $envelope->sourceReference,
            sourceName: $envelope->sourceName,
            resourceReference: $envelope->resourceReference,
            observedAt: $envelope->observedAt,
            payload: $envelope->payload,
            contentType: $envelope->contentType,
            metadata: $envelope->metadata(),
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
