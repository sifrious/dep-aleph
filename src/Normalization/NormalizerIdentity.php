<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Normalization;

use InvalidArgumentException;

final readonly class NormalizerIdentity
{
    public function __construct(
        public string $id,
        public int $version,
    ) {
        if (preg_match('/^[a-z0-9]+(?:[.-][a-z0-9]+)*$/', $id) !== 1) {
            throw new InvalidArgumentException("Normalizer id [{$id}] must be a lowercase slug.");
        }

        if ($version < 1) {
            throw new InvalidArgumentException('Normalizer version must be a positive integer.');
        }
    }

    public function reference(): string
    {
        return $this->id.'@'.$this->version;
    }

    public function withVersion(int $version): self
    {
        return new self($this->id, $version);
    }

    public function is(self $other): bool
    {
        return $this->id === $other->id && $this->version === $other->version;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'version' => $this->version];
    }
}
