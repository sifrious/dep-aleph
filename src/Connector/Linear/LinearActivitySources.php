<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Linear;

use InvalidArgumentException;

final class LinearActivitySources
{
    /** @var array<string, LinearActivitySource> */
    private array $sources = [];

    public function register(LinearActivitySource $source): void
    {
        $this->sources[$source->sourceReference()] = $source;
    }

    public function get(string $reference): LinearActivitySource
    {
        return $this->sources[$reference]
            ?? throw new InvalidArgumentException("Linear activity source [{$reference}] is not registered.");
    }
}
