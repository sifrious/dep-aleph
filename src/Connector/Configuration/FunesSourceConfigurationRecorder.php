<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Configuration;

use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;

/**
 * Records the accepted declaration as an observation under the source's stable reference.
 * The record carries the opaque credential reference and never credential material, because
 * the configurator refuses inline secret values before this point.
 */
final readonly class FunesSourceConfigurationRecorder implements SourceConfigurationRecorder
{
    public function __construct(private EnvelopeSubmitter $submitter) {}

    public function record(SourceConfiguration $configuration): void
    {
        $this->submitter->submit(new ObservationEnvelope(
            sourceReference: $configuration->sourceReference,
            sourceName: $configuration->name,
            resourceReference: $configuration->resourceReference(),
            observedAt: $configuration->configuredAt,
            payload: (string) json_encode($configuration->toArray(), JSON_THROW_ON_ERROR),
            provenance: new Provenance(
                connectorId: $configuration->connectorId,
                connectorVersion: $configuration->connectorVersion,
                installationId: $configuration->sourceReference,
                capturedAt: $configuration->configuredAt,
            ),
            contentType: 'application/json',
            stream: 'sources.configure',
            eventType: 'source.configured',
            providerId: $configuration->sourceKey,
        ));
    }
}
