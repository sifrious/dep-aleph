<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Normalization;

use InvalidArgumentException;

final readonly class RawReference
{
    public function __construct(
        public string $sourceReference,
        public string $resourceReference,
        public string $inputHash,
        public ?string $contentType = null,
        public ?string $observationId = null,
    ) {
        if ($sourceReference === '' || $resourceReference === '') {
            throw new InvalidArgumentException('Raw evidence must name a source and a resource.');
        }

        if (preg_match('/^[0-9a-f]{64}$/', $inputHash) !== 1) {
            throw new InvalidArgumentException('Raw evidence must carry a sha256 input hash.');
        }
    }

    public static function forPayload(
        string $sourceReference,
        string $resourceReference,
        string $payload,
        ?string $contentType = null,
        ?string $observationId = null,
    ): self {
        return new self(
            $sourceReference,
            $resourceReference,
            hash('sha256', $payload),
            $contentType,
            $observationId,
        );
    }

    public function matches(string $payload): bool
    {
        return hash_equals($this->inputHash, hash('sha256', $payload));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'source' => $this->sourceReference,
            'resource' => $this->resourceReference,
            'input_hash' => $this->inputHash,
            'content_type' => $this->contentType,
            'observation' => $this->observationId,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
