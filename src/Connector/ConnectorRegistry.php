<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector;

final class ConnectorRegistry
{
    /** @var array<string, Connector> */
    private array $connectors = [];

    public function register(Connector $connector): void
    {
        $this->connectors[$connector->id()] = $connector;
    }

    public function has(string $id): bool
    {
        return isset($this->connectors[$id]);
    }

    public function get(string $id): Connector
    {
        return $this->connectors[$id] ?? throw UnknownConnector::named($id);
    }

    /**
     * @return array<string, Connector>
     */
    public function all(): array
    {
        return $this->connectors;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->connectors);
    }

    public function manifest(string $id): ConnectorManifest
    {
        return ConnectorManifest::for($this->get($id));
    }

    /**
     * @return list<ConnectorManifest>
     */
    public function manifests(): array
    {
        return array_values(array_map(
            static fn (Connector $connector): ConnectorManifest => ConnectorManifest::for($connector),
            $this->connectors,
        ));
    }

    /**
     * @return list<Connector>
     */
    public function supporting(Capability $capability): array
    {
        return array_values(array_filter(
            $this->connectors,
            static fn (Connector $connector): bool => CapabilitySet::of($connector)->has($capability),
        ));
    }
}
