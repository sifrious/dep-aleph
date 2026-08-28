<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Normalization;

use InvalidArgumentException;

final readonly class CandidateSchema
{
    public function __construct(
        public string $name,
        public int $version,
    ) {
        if (preg_match('/^[a-z0-9]+(?:\.[a-z0-9]+)*$/', $name) !== 1) {
            throw new InvalidArgumentException("Candidate schema [{$name}] must be a lowercase dotted slug.");
        }

        if ($version < 1) {
            throw new InvalidArgumentException('Candidate schema version must be a positive integer.');
        }
    }

    public function reference(): string
    {
        return $this->name.'@'.$this->version;
    }

    public function withVersion(int $version): self
    {
        return new self($this->name, $version);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'version' => $this->version];
    }
}
