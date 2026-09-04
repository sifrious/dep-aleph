<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Bibliography;

use InvalidArgumentException;
use Stringable;

final readonly class SourceIdentifier implements Stringable
{
    public string $source;

    public string $identifier;

    public function __construct(string $source, string $identifier)
    {
        $source = strtolower(trim($source));

        if ($source === '' || trim($identifier) === '') {
            throw new InvalidArgumentException('A source identifier requires non-empty source and identifier values.');
        }

        $this->source = $source;
        $this->identifier = $identifier;
    }

    public function key(): string
    {
        return hash('sha256', $this->source."\0".$this->identifier);
    }

    /**
     * @return array{source: string, identifier: string}
     */
    public function toArray(): array
    {
        return ['source' => $this->source, 'identifier' => $this->identifier];
    }

    public function __toString(): string
    {
        return $this->source.':'.$this->identifier;
    }
}
