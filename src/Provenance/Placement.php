<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Provenance;

use InvalidArgumentException;

/**
 * The position of a unit within a sentence, paragraph, section, or document.
 *
 * Ordinals are one-based. Normalized positions run from 0.0 to 1.0 and are
 * undefined for a frame containing only one position. Region classification is
 * supplied by the caller because its policy belongs to the producing analysis.
 */
final readonly class Placement
{
    private function __construct(
        public PlacementFrame $frame,
        public int $ordinal,
        public PlacementRegion $region,
        public ?float $normalizedPosition,
    ) {}

    public static function at(PlacementFrame $frame, int $ordinal, PlacementRegion $region): self
    {
        if ($ordinal < 1 || $ordinal > $frame->total) {
            throw new InvalidArgumentException('A placement ordinal must fall within its frame.');
        }

        return new self(
            frame: $frame,
            ordinal: $ordinal,
            region: $region,
            normalizedPosition: $frame->total === 1 ? null : ($ordinal - 1) / ($frame->total - 1),
        );
    }

    public function relationTo(self $other): PlacementRelation
    {
        $this->assertSameFrame($other);

        return match ($this->ordinal <=> $other->ordinal) {
            -1 => PlacementRelation::Preceding,
            0 => PlacementRelation::Same,
            1 => PlacementRelation::Following,
        };
    }

    public function ordinalDistanceTo(self $other): int
    {
        $this->assertSameFrame($other);

        return abs($this->ordinal - $other->ordinal);
    }

    public function normalizedDistanceTo(self $other): ?float
    {
        $this->assertSameFrame($other);

        if ($this->normalizedPosition === null || $other->normalizedPosition === null) {
            return null;
        }

        return abs($this->normalizedPosition - $other->normalizedPosition);
    }

    /**
     * @return array{
     *     scope: string,
     *     absolute_ordinal: int,
     *     normalized_position?: float,
     *     region: string
     * }
     */
    public function toArray(): array
    {
        return array_filter([
            'scope' => $this->frame->scope->value,
            'absolute_ordinal' => $this->ordinal,
            'normalized_position' => $this->normalizedPosition,
            'region' => $this->region->value,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function assertSameFrame(self $other): void
    {
        if ($this->frame !== $other->frame) {
            throw new InvalidArgumentException('Placements can only be compared within the same coordinate frame.');
        }
    }
}
