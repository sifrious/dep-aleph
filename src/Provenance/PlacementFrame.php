<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Provenance;

use InvalidArgumentException;

/**
 * An in-memory coordinate frame for placements in one specific container.
 *
 * The frame deliberately carries no persisted artifact or textual-unit identity.
 */
final readonly class PlacementFrame
{
    public function __construct(
        public PlacementScope $scope,
        public int $total,
    ) {
        if ($total < 1) {
            throw new InvalidArgumentException('A placement frame must contain at least one position.');
        }
    }
}
