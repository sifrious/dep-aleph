<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Provenance;

use InvalidArgumentException;

/**
 * Coordinates within one immutable source.
 *
 * Byte and character coordinates are zero-based, half-open ranges. Line
 * coordinates are one-based, inclusive ranges.
 */
final readonly class SourceRange
{
    public function __construct(
        public ?int $byteStart = null,
        public ?int $byteEnd = null,
        public ?int $characterStart = null,
        public ?int $characterEnd = null,
        public ?int $lineStart = null,
        public ?int $lineEnd = null,
    ) {
        $this->validatePair('byte', $byteStart, $byteEnd, 0);
        $this->validatePair('character', $characterStart, $characterEnd, 0);
        $this->validatePair('line', $lineStart, $lineEnd, 1);

        if ($byteStart === null && $characterStart === null && $lineStart === null) {
            throw new InvalidArgumentException('A source range requires at least one coordinate pair.');
        }
    }

    /**
     * @return array{
     *     byte?: array{start: int, end: int},
     *     character?: array{start: int, end: int},
     *     line?: array{start: int, end: int}
     * }
     */
    public function toArray(): array
    {
        $range = [];

        if ($this->byteStart !== null && $this->byteEnd !== null) {
            $range['byte'] = ['start' => $this->byteStart, 'end' => $this->byteEnd];
        }

        if ($this->characterStart !== null && $this->characterEnd !== null) {
            $range['character'] = ['start' => $this->characterStart, 'end' => $this->characterEnd];
        }

        if ($this->lineStart !== null && $this->lineEnd !== null) {
            $range['line'] = ['start' => $this->lineStart, 'end' => $this->lineEnd];
        }

        return $range;
    }

    private function validatePair(string $name, ?int $start, ?int $end, int $minimum): void
    {
        if (($start === null) !== ($end === null)) {
            throw new InvalidArgumentException("The {$name} start and end coordinates must be provided together.");
        }

        if ($start !== null && $end !== null && ($start < $minimum || $end < $start)) {
            throw new InvalidArgumentException("The {$name} coordinates do not form a valid range.");
        }
    }
}
