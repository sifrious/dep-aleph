<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Normalization;

final readonly class NormalizationLineage
{
    public function __construct(
        public NormalizerIdentity $normalizer,
        public CandidateSchema $schema,
        public RawReference $raw,
        public bool $deterministic = true,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'normalizer' => $this->normalizer->reference(),
            'normalizer_id' => $this->normalizer->id,
            'normalizer_version' => $this->normalizer->version,
            'candidate_schema' => $this->schema->reference(),
            'candidate_schema_version' => $this->schema->version,
            'deterministic' => $this->deterministic,
            'raw' => $this->raw->toArray(),
        ];
    }
}
