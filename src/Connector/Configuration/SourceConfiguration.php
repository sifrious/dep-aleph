<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Configuration;

use DateTimeImmutable;
use Sifrious\Aleph\Connector\CredentialKind;

/**
 * The neutral configuration record. Its source reference is the stable identity every
 * later run, checkpoint, and Funes submission uses.
 */
final readonly class SourceConfiguration
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(
        public string $sourceReference,
        public string $connectorId,
        public string $connectorVersion,
        public string $sourceKey,
        public string $name,
        public array $values,
        public ?string $credentialReference,
        public ?CredentialKind $credentialKind,
        public ?string $owner,
        public DateTimeImmutable $configuredAt,
    ) {}

    public function resourceReference(): string
    {
        return 'aleph:source-configuration/'.$this->sourceReference;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_reference' => $this->sourceReference,
            'connector' => $this->connectorId,
            'connector_version' => $this->connectorVersion,
            'source_key' => $this->sourceKey,
            'name' => $this->name,
            'values' => $this->values,
            'credential_reference' => $this->credentialReference,
            'credential_kind' => $this->credentialKind?->value,
            'owner' => $this->owner,
            'configured_at' => $this->configuredAt->format(DATE_ATOM),
        ];
    }
}
