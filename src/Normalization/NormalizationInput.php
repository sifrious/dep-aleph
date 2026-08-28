<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Normalization;

use InvalidArgumentException;
use Sifrious\Aleph\Envelope\Provenance;

final readonly class NormalizationInput
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public RawReference $raw,
        public string $payload,
        public Provenance $provenance,
        public array $context = [],
        public ?string $contextVersion = null,
        public ?string $ingestionAttemptId = null,
    ) {
        if (! $raw->matches($payload)) {
            throw new InvalidArgumentException(
                'Payload does not match the declared input hash; raw evidence and payload have diverged.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function for(
        string $sourceReference,
        string $resourceReference,
        string $payload,
        Provenance $provenance,
        ?string $contentType = null,
        ?string $observationId = null,
        array $context = [],
        ?string $contextVersion = null,
        ?string $ingestionAttemptId = null,
    ): self {
        return new self(
            RawReference::forPayload($sourceReference, $resourceReference, $payload, $contentType, $observationId),
            $payload,
            $provenance,
            $context,
            $contextVersion,
            $ingestionAttemptId,
        );
    }

    public function inputHash(): string
    {
        return $this->raw->inputHash;
    }

    public function contentType(): ?string
    {
        return $this->raw->contentType;
    }

    public function contextValue(string $key, mixed $default = null): mixed
    {
        return $this->context[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeJson(): array
    {
        $decoded = json_decode($this->payload, true);

        return is_array($decoded) ? $decoded : [];
    }
}
