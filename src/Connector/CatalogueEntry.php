<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector;

final readonly class CatalogueEntry
{
    public function __construct(
        public string $id,
        public string $name,
        public string $version,
        public ?string $package,
        public bool $enabled,
        public ConnectorManifest $manifest,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'version' => $this->version,
            'package' => $this->package,
            'enabled' => $this->enabled,
            'capabilities' => $this->manifest->capabilityIds(),
            'operations' => $this->manifest->availableOperations(),
            'configuration' => $this->manifest->configuration->toArray(),
        ];
    }
}
