<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Values;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, DiscoveredSource>
 */
final readonly class DiscoveredSources implements Countable, IteratorAggregate
{
    /** @var list<DiscoveredSource> */
    public array $sources;

    public function __construct(DiscoveredSource ...$sources)
    {
        $this->sources = array_values($sources);
    }

    public function count(): int
    {
        return count($this->sources);
    }

    public function getIterator(): Traversable
    {
        return new \ArrayIterator($this->sources);
    }

    /**
     * @return list<string>
     */
    public function references(): array
    {
        return array_map(
            static fn (DiscoveredSource $source): string => $source->reference,
            $this->sources,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (DiscoveredSource $source): array => $source->toArray(),
            $this->sources,
        );
    }
}
