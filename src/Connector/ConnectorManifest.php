<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector;

final readonly class ConnectorManifest
{
    /**
     * @param  list<Capability>  $capabilities
     */
    private function __construct(
        public string $id,
        public string $name,
        public string $version,
        public array $capabilities,
        public ConfigurationSchema $configuration,
    ) {}

    public static function for(Connector $connector): self
    {
        return new self(
            $connector->id(),
            $connector->name(),
            $connector->version(),
            CapabilitySet::of($connector)->all(),
            $connector->configuration(),
        );
    }

    public function supports(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    /**
     * @return list<Capability>
     */
    public function dispatchableCapabilities(): array
    {
        return array_values(array_filter(
            $this->capabilities,
            static fn (Capability $capability): bool => $capability->isDispatchable(),
        ));
    }

    /**
     * @return list<string>
     */
    public function capabilityIds(): array
    {
        return array_map(
            static fn (Capability $capability): string => $capability->value,
            $this->capabilities,
        );
    }

    /**
     * @return list<string>
     */
    public function availableOperations(): array
    {
        return array_map(
            static fn (Capability $capability): string => $capability->value,
            $this->dispatchableCapabilities(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'version' => $this->version,
            'capabilities' => $this->capabilityIds(),
            'operations' => $this->availableOperations(),
            'configuration' => $this->configuration->toArray(),
        ];
    }
}
