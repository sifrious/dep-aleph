<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Envelope;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ObservationEnvelope
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param  list<string>  $discoveries
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
        public array $discoveries = [],
    ) {
        if ($sourceReference === '' || $resourceReference === '') {
            throw new InvalidArgumentException('Source and resource references must not be empty.');
        }

        if (! is_array($artifacts) || ! is_array($extensions)) {
            throw new InvalidArgumentException('Artifacts and extensions must be arrays.');
        }

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
                'artifacts' => $this->artifacts === []
                    ? null
                    : array_map(
                        static fn (ArtifactReference $artifact): array => $artifact->toArray(),
                        $this->artifacts,
                    ),
                'provenance' => $this->provenance->toArray(),
            ], static fn (mixed $value): bool => $value !== null),
            'extensions' => array_map(
                static fn (ExtensionMetadata $extension): array => $extension->toArray(),
                $this->extensions,
            ),
        ];
    }
}
