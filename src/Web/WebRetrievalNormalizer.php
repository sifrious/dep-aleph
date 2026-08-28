<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

use Sifrious\Aleph\Envelope\ExtensionMetadata;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Normalization\CandidateEnvelope;
use Sifrious\Aleph\Normalization\CandidateEnvelopes;
use Sifrious\Aleph\Normalization\CandidateSchema;
use Sifrious\Aleph\Normalization\NormalizationInput;
use Sifrious\Aleph\Normalization\Normalizer;
use Sifrious\Aleph\Normalization\NormalizerIdentity;

final readonly class WebRetrievalNormalizer implements Normalizer
{
    public function identity(): NormalizerIdentity
    {
        return new NormalizerIdentity('web-retrieval', 1);
    }

    public function schema(): CandidateSchema
    {
        return new CandidateSchema('web.retrieval', 1);
    }

    public function supports(NormalizationInput $input): bool
    {
        return $input->contextValue('http_status') !== null;
    }

    public function normalize(NormalizationInput $input): CandidateEnvelopes
    {
        $context = $input->context;

        $envelope = new ObservationEnvelope(
            sourceReference: $input->raw->sourceReference,
            sourceName: (string) ($context['source_name'] ?? $input->raw->sourceReference),
            resourceReference: $input->raw->resourceReference,
            observedAt: $input->provenance->capturedAt,
            payload: $input->payload,
            provenance: $input->provenance,
            contentType: $input->contentType() ?? 'application/octet-stream',
            eventType: 'web.resource.retrieved',
            extensions: [
                new ExtensionMetadata('web.retrieval', 1, [
                    'http_status' => $context['http_status'],
                    'requested_url' => $context['requested_url'] ?? null,
                    'final_url' => $context['final_url'] ?? null,
                    'redirect_chain' => $context['redirect_chain'] ?? [],
                    'ingestion_run_id' => $context['ingestion_run_id'] ?? null,
                    'discovery_origin' => $context['discovery_origin'] ?? null,
                ]),
            ],
            discoveries: array_values((array) ($context['discoveries'] ?? [])),
        );

        return new CandidateEnvelopes(
            new CandidateEnvelope($this->schema(), $this->identity(), $input->raw, $envelope),
        );
    }
}
