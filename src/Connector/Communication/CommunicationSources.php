<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Communication;

use InvalidArgumentException;

final class CommunicationSources
{
    /** @var array<string, CommunicationSource> */
    private array $sources = [];

    public function register(CommunicationSource $source): void
    {
        $this->sources[$source->provider()->value.':'.$source->sourceReference()] = $source;
    }

    public function get(CommunicationProvider $provider, string $reference): CommunicationSource
    {
        return $this->sources[$provider->value.':'.$reference]
            ?? throw new InvalidArgumentException("Communication source [{$provider->value}:{$reference}] is not registered.");
    }
}
