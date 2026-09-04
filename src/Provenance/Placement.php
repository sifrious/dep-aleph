<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Provenance;

use InvalidArgumentException;

/**
 * The position of a unit within a sentence, paragraph, section, or document.
 *
 * Ordinals are one-based. Normalized positions run from 0.0 to 1.0.
 */
final readonly class Placement
{
    public function __construct(
        public PlacementScope $scope,
        public int $ordinal,
        public float $normalizedPosition,
    ) {
        if ($ordinal < 1) {
            throw new InvalidArgumentException('A placement ordinal must be at least one.');
        }

        if (! is_finite($normalizedPosition) || $normalizedPosition < 0.0 || $normalizedPosition > 1.0) {
            throw new InvalidArgumentException('A normalized placement must be between zero and one.');
        }
    }

    public static function fromOrdinal(PlacementScope $scope, int $ordinal, int $total): self
    {
        if ($total < 1 || $ordinal > $total) {
            throw new InvalidArgumentException('A placement ordinal must fall within the supplied total.');
        }

        $normalizedPosition = $total === 1
            ? 0.5
            : ($ordinal - 1) / ($total - 1);

        return new self($scope, $ordinal, $normalizedPosition);
    }

    public function region(): PlacementRegion
    {
        return match (true) {
            $this->normalizedPosition <= (1 / 3) => PlacementRegion::Beginning,
            $this->normalizedPosition >= (2 / 3) => PlacementRegion::End,
            default => PlacementRegion::Middle,
        };
    }

    public function relationTo(self $other): PlacementRelation
    {
        $this->assertSameScope($other);

        return match ($this->ordinal <=> $other->ordinal) {
            -1 => PlacementRelation::Preceding,
            0 => PlacementRelation::Same,
            1 => PlacementRelation::Following,
        };
    }

    public function ordinalDistanceTo(self $other): int
    {
        $this->assertSameScope($other);

        return abs($this->ordinal - $other->ordinal);
    }

    public function normalizedDistanceTo(self $other): float
    {
        $this->assertSameScope($other);

        return abs($this->normalizedPosition - $other->normalizedPosition);
    }

    /**
     * @return array{
     *     scope: string,
     *     absolute_ordinal: int,
     *     normalized_position: float,
     *     region: string
     * }
     */
    public function toArray(): array
    {
        return [
            'scope' => $this->scope->value,
            'absolute_ordinal' => $this->ordinal,
            'normalized_position' => $this->normalizedPosition,
            'region' => $this->region()->value,
        ];
    }

    private function assertSameScope(self $other): void
    {
        if ($this->scope !== $other->scope) {
            throw new InvalidArgumentException('Placements can only be compared within the same scope.');
        }
    }
}
