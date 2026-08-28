<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, Capability>
 */
final readonly class CapabilitySet implements Countable, IteratorAggregate
{
    /** @var list<Capability> */
    public array $capabilities;

    public function __construct(Capability ...$capabilities)
    {
        $this->capabilities = array_values(array_unique($capabilities, SORT_REGULAR));
    }

    public static function of(Connector $connector): self
    {
        $supported = [];

        foreach (Capability::cases() as $capability) {
            $contract = $capability->contract();

            if ($connector instanceof $contract) {
                $supported[] = $capability;
            }
        }

        return new self(...$supported);
    }

    public function has(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    /**
     * @return list<Capability>
     */
    public function all(): array
    {
        return $this->capabilities;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_map(
            static fn (Capability $capability): string => $capability->value,
            $this->capabilities,
        );
    }

    public function count(): int
    {
        return count($this->capabilities);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->capabilities);
    }
}
