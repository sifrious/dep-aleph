<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Envelope;

use DateTimeImmutable;
use InvalidArgumentException;
use Sifrious\Aleph\Normalization\NormalizationLineage;

final readonly class ObservationEnvelope
{
    public const SCHEMA_VERSION = 1;

    /** @var list<DiscoveryReference> */
    public array $discoveries;

    /**
     * @param  list<DiscoveryReference|string>  $discoveries
     */
    public function __construct(
        public string $sourceReference,
        public string $sourceName,
        public string $resourceReference,
        public DateTimeImmutable $observedAt,
        public string $payload,
        public Provenance $provenance,
        public string $contentType = 'application/octet-stream',
        public ?string $account = null,
        public ?string $stream = null,
        public ?string $eventType = null,
        public ?string $providerId = null,
        public ?string $providerRevision = null,
        public mixed $artifacts = [],
        public mixed $extensions = [],
        array $discoveries = [],
        public ?NormalizationLineage $normalization = null,
        public ?DateTimeImmutable $occurredAt = null,
    ) {
        if ($sourceReference === '' || $resourceReference === '') {
            throw new InvalidArgumentException('Source and resource references must not be empty.');
        }

        if (! is_array($artifacts) || ! is_array($extensions)) {
            throw new InvalidArgumentException('Artifacts and extensions must be arrays.');
        }

        $this->discoveries = array_map(DiscoveryReference::from(...), $discoveries);

        foreach ($artifacts as $artifact) {
            if (! $artifact instanceof ArtifactReference) {
                throw new InvalidArgumentException('Artifacts must contain only ArtifactReference values.');
            }
        }

        $namespaces = [];

        foreach ($extensions as $extension) {
            if (! $extension instanceof ExtensionMetadata) {
                throw new InvalidArgumentException('Extensions must contain only ExtensionMetadata values.');
            }

            if (isset($namespaces[$extension->namespace])) {
                throw new InvalidArgumentException(
                    "Extension namespace [{$extension->namespace}] is declared more than once."
                );
            }

            $namespaces[$extension->namespace] = true;
        }
    }

    public function withNormalization(NormalizationLineage $lineage): self
    {
        return new self(
            sourceReference: $this->sourceReference,
            sourceName: $this->sourceName,
            resourceReference: $this->resourceReference,
            observedAt: $this->observedAt,
            payload: $this->payload,
            provenance: $this->provenance,
            contentType: $this->contentType,
            account: $this->account,
            stream: $this->stream,
            eventType: $this->eventType,
            providerId: $this->providerId,
            providerRevision: $this->providerRevision,
            artifacts: $this->artifacts,
            extensions: $this->extensions,
            discoveries: $this->discoveries,
            normalization: $lineage,
            occurredAt: $this->occurredAt,
        );
    }

    public function extension(string $namespace): ?ExtensionMetadata
    {
        foreach ($this->extensions as $extension) {
            if ($extension->namespace === $namespace) {
                return $extension;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return [
            'aleph' => array_filter([
                'envelope_version' => self::SCHEMA_VERSION,
                'account' => $this->account,
                'stream' => $this->stream,
                'event_type' => $this->eventType,
                'provider_id' => $this->providerId,
                'provider_revision' => $this->providerRevision,
                'occurred_at' => $this->occurredAt?->format(DATE_ATOM),
                'artifacts' => $this->artifacts === []
                    ? null
                    : array_map(
                        static fn (ArtifactReference $artifact): array => $artifact->toArray(),
                        $this->artifacts,
                    ),
                'provenance' => $this->provenance->toArray(),
                'normalization' => $this->normalization?->toArray(),
            ], static fn (mixed $value): bool => $value !== null),
            'extensions' => array_map(
                static fn (ExtensionMetadata $extension): array => $extension->toArray(),
                $this->extensions,
            ),
        ];
    }
}
