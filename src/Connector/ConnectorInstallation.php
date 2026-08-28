<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector;

use DateTimeImmutable;

final readonly class ConnectorInstallation
{
    /**
     * @param  array<string, mixed>  $configuration
     */
    public function __construct(
        public string $id,
        public string $connectorId,
        public string $connectorVersion,
        public string $label,
        public bool $enabled,
        public array $configuration,
        public ?string $credentialsReference,
        public ?string $owner,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'connector' => $this->connectorId,
            'connector_version' => $this->connectorVersion,
            'label' => $this->label,
            'enabled' => $this->enabled,
            'configuration' => $this->configuration,
            'credentials_reference' => $this->credentialsReference,
            'owner' => $this->owner,
            'created_at' => $this->createdAt->format(DATE_ATOM),
        ];
    }
}
