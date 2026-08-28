<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector;

final readonly class ConnectorCatalogue
{
    /**
     * @param  list<string>  $disabled
     */
    public function __construct(
        private ConnectorRegistry $registry,
        private array $disabled = [],
    ) {}

    /**
     * @return list<CatalogueEntry>
     */
    public function entries(): array
    {
        return array_values(array_map(
            fn (Connector $connector): CatalogueEntry => $this->entryFor($connector),
            $this->registry->all(),
        ));
    }

    /**
     * @return list<CatalogueEntry>
     */
    public function enabled(): array
    {
        return array_values(array_filter(
            $this->entries(),
            static fn (CatalogueEntry $entry): bool => $entry->enabled,
        ));
    }

    public function find(string $connectorId): ?CatalogueEntry
    {
        return $this->registry->has($connectorId)
            ? $this->entryFor($this->registry->get($connectorId))
            : null;
    }

    public function isEnabled(string $connectorId): bool
    {
        return $this->registry->has($connectorId)
            && ! in_array($connectorId, $this->disabled, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $catalogue = [];

        foreach ($this->entries() as $entry) {
            $catalogue[$entry->id] = $entry->toArray();
        }

        return $catalogue;
    }

    private function entryFor(Connector $connector): CatalogueEntry
    {
        return new CatalogueEntry(
            $connector->id(),
            $connector->name(),
            $connector->version(),
            PackageLocator::for($connector),
            $this->isEnabled($connector->id()),
            ConnectorManifest::for($connector),
        );
    }
}
