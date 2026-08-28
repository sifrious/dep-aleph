<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Inventory;

use Sifrious\Aleph\Extraction\ExtractionStatus;
use Sifrious\Aleph\Web\DiscoveryOrigin;
use Sifrious\Aleph\Web\FrontierState;

final readonly class Inventory
{
    /**
     * @param  list<InventoryResource>  $resources
     */
    public function __construct(
        public InventoryBounds $bounds,
        public array $resources,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function totals(): array
    {
        return [
            'resources' => count($this->resources),
            'by_state' => $this->tally(
                array_map(fn (FrontierState $state): string => $state->value, FrontierState::cases()),
                fn (InventoryResource $resource): string => $resource->state->value,
            ),
            'by_freshness' => $this->tally(
                array_map(fn (Freshness $freshness): string => $freshness->value, Freshness::cases()),
                fn (InventoryResource $resource): string => $resource->freshness->value,
            ),
            'unsuccessful' => $this->count(fn (InventoryResource $r): bool => $r->httpStatus !== null && ($r->httpStatus < 200 || $r->httpStatus >= 300)),
            'failures' => $this->count(fn (InventoryResource $r): bool => $r->failure !== null),
            'extraction_errors' => $this->count(fn (InventoryResource $r): bool => $r->extractionStatus === ExtractionStatus::Failed),
            'external' => $this->count(fn (InventoryResource $r): bool => $r->external),
            'external_embeds' => $this->count(fn (InventoryResource $r): bool => $r->external && $this->embedded($r)),
            'calendar_like' => $this->count(fn (InventoryResource $r): bool => $r->calendarLike),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'bounds' => $this->bounds->toArray(),
            'totals' => $this->totals(),
            'resources' => array_map(
                fn (InventoryResource $resource): array => $resource->toArray(),
                $this->resources,
            ),
        ];
    }

    private function embedded(InventoryResource $resource): bool
    {
        return $resource->origin === DiscoveryOrigin::Iframe || $resource->origin === DiscoveryOrigin::Embed;
    }

    /**
     * @param  list<string>  $keys
     * @param  callable(InventoryResource): string  $key
     * @return array<string, int>
     */
    private function tally(array $keys, callable $key): array
    {
        $counts = array_fill_keys($keys, 0);

        foreach ($this->resources as $resource) {
            $counts[$key($resource)]++;
        }

        return $counts;
    }

    /**
     * @param  callable(InventoryResource): bool  $matches
     */
    private function count(callable $matches): int
    {
        return count(array_filter($this->resources, $matches));
    }
}
