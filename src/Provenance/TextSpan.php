<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Provenance;

use InvalidArgumentException;

/**
 * A source range with optional zero-based, half-open tokenizer boundaries.
 */
final readonly class TextSpan
{
    public function __construct(
        public SourceRange $sourceRange,
        public ?int $tokenStart = null,
        public ?int $tokenEnd = null,
    ) {
        if (($tokenStart === null) !== ($tokenEnd === null)) {
            throw new InvalidArgumentException('The token start and end boundaries must be provided together.');
        }

        if ($tokenStart !== null && $tokenEnd !== null && ($tokenStart < 0 || $tokenEnd < $tokenStart)) {
            throw new InvalidArgumentException('The token boundaries do not form a valid range.');
        }
    }

    /**
     * @return array{
     *     source_range: array<string, array{start: int, end: int}>,
     *     token?: array{start: int, end: int}
     * }
     */
    public function toArray(): array
    {
        $span = ['source_range' => $this->sourceRange->toArray()];

        if ($this->tokenStart !== null) {
            $span['token'] = ['start' => $this->tokenStart, 'end' => $this->tokenEnd];
        }

        return $span;
    }
}
