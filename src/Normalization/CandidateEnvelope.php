<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Normalization;

use Sifrious\Aleph\Envelope\ObservationEnvelope;

final readonly class CandidateEnvelope
{
    public function __construct(
        public CandidateSchema $schema,
        public NormalizerIdentity $normalizer,
        public RawReference $raw,
        public ObservationEnvelope $envelope,
        public bool $deterministic = true,
    ) {}

    public function lineage(): NormalizationLineage
    {
        return new NormalizationLineage($this->normalizer, $this->schema, $this->raw, $this->deterministic);
    }

    public function toObservationEnvelope(): ObservationEnvelope
    {
        return $this->envelope->withNormalization($this->lineage());
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'schema' => $this->schema->reference(),
            'normalizer' => $this->normalizer->reference(),
            'raw' => $this->raw->toArray(),
            'resource' => $this->envelope->resourceReference,
            'deterministic' => $this->deterministic,
        ];
    }
}
