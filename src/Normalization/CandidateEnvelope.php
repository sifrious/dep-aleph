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

    public const DIRECT_SCHEMA = 'aleph.envelope';

    public const DIRECT_NORMALIZER = 'aleph.direct';

    public static function forEnvelope(ObservationEnvelope $envelope): self
    {
        $lineage = $envelope->normalization;

        if ($lineage === null) {
            return self::direct($envelope);
        }

        return new self(
            schema: $lineage->schema,
            normalizer: $lineage->normalizer,
            raw: $lineage->raw,
            envelope: $envelope,
            deterministic: $lineage->deterministic,
        );
    }

    public static function direct(ObservationEnvelope $envelope): self
    {
        return new self(
            schema: new CandidateSchema(self::DIRECT_SCHEMA, ObservationEnvelope::SCHEMA_VERSION),
            normalizer: new NormalizerIdentity(self::DIRECT_NORMALIZER, 1),
            raw: RawReference::forPayload(
                $envelope->sourceReference,
                $envelope->resourceReference,
                $envelope->payload,
                $envelope->contentType,
            ),
            envelope: $envelope,
        );
    }

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
