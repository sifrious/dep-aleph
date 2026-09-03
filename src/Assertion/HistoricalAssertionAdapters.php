<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Assertion;

final class HistoricalAssertionAdapters
{
    /** @var array<string, HistoricalAssertionAdapter> */
    private array $adapters = [];

    /** @param iterable<HistoricalAssertionAdapter> $adapters */
    public function __construct(iterable $adapters = [])
    {
        foreach ($adapters as $adapter) {
            $this->register($adapter);
        }
    }

    public function register(HistoricalAssertionAdapter $adapter): void
    {
        $provider = $adapter->provider();
        if (isset($this->adapters[$provider])) {
            throw new AssertionNormalizationException("Assertion provider {$provider} is already registered.");
        }
        $this->adapters[$provider] = $adapter;
    }

    public function for(string $provider): HistoricalAssertionAdapter
    {
        return $this->adapters[$provider] ?? throw new UnsupportedAssertionProvider("No historical assertion adapter is registered for {$provider}.");
    }

    /** @return list<string> */
    public function providers(): array
    {
        return array_keys($this->adapters);
    }
}
