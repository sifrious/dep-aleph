<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Envelope;

use Sifrious\Funes\Value\MetadataAssertion;
use Sifrious\Funes\Value\MetadataDraft;
use Sifrious\Funes\Value\Observation;
use UnexpectedValueException;

final class ObservationMetadata
{
    public const ENVELOPE_NAMESPACE = 'aleph:envelope';

    public const EXTENSION_PREFIX = 'aleph:extension/';

    /**
     * @param  array<string, mixed>  $sourceScopes
     * @return list<MetadataDraft>
     */
    public static function drafts(ObservationEnvelope $envelope, array $sourceScopes): array
    {
        $aleph = $envelope->metadata()['aleph'];
        $aleph['source_scopes'] = $sourceScopes;
        $drafts = [new MetadataDraft(
            self::ENVELOPE_NAMESPACE,
            (string) ObservationEnvelope::SCHEMA_VERSION,
            $aleph,
        )];

        foreach ($envelope->extensions as $extension) {
            $drafts[] = new MetadataDraft(
                self::EXTENSION_PREFIX.$extension->namespace,
                (string) $extension->version,
                $extension->data,
            );
        }

        return $drafts;
    }

    /**
     * @return array<string, mixed>
     */
    public static function aleph(Observation $observation): array
    {
        return self::attributes($observation, self::ENVELOPE_NAMESPACE);
    }

    /**
     * @return array<string, mixed>
     */
    public static function extension(Observation $observation, string $namespace): array
    {
        return self::attributes($observation, self::EXTENSION_PREFIX.$namespace);
    }

    /**
     * @return list<ExtensionMetadata>
     */
    public static function extensions(Observation $observation): array
    {
        $extensions = [];

        foreach ($observation->metadata as $metadata) {
            if (! str_starts_with($metadata->namespace, self::EXTENSION_PREFIX)) {
                continue;
            }

            if (! is_array($metadata->attributes)) {
                throw new UnexpectedValueException("Metadata [{$metadata->namespace}] does not contain structured attributes.");
            }

            $extensions[] = new ExtensionMetadata(
                substr($metadata->namespace, strlen(self::EXTENSION_PREFIX)),
                (int) $metadata->schemaVersion,
                $metadata->attributes,
            );
        }

        return $extensions;
    }

    /**
     * @return array<string, mixed>
     */
    private static function attributes(Observation $observation, string $namespace): array
    {
        $metadata = $observation->metadata($namespace);
        $latest = $metadata === [] ? null : $metadata[count($metadata) - 1];

        return $latest instanceof MetadataAssertion && is_array($latest->attributes)
            ? $latest->attributes
            : [];
    }
}
