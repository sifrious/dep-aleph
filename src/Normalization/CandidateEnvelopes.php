<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Normalization;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, CandidateEnvelope>
 */
final readonly class CandidateEnvelopes implements Countable, IteratorAggregate
{
    /** @var list<CandidateEnvelope> */
    public array $candidates;

    public function __construct(CandidateEnvelope ...$candidates)
    {
        $this->candidates = array_values($candidates);
    }

    public static function none(): self
    {
        return new self;
    }

    public function isEmpty(): bool
    {
        return $this->candidates === [];
    }

    public function count(): int
    {
        return count($this->candidates);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->candidates);
    }

    /**
     * @return list<CandidateEnvelope>
     */
    public function all(): array
    {
        return $this->candidates;
    }

    public function first(): ?CandidateEnvelope
    {
        return $this->candidates[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function describe(): array
    {
        return array_map(
            static fn (CandidateEnvelope $candidate): array => $candidate->describe(),
            $this->candidates,
        );
    }
}
