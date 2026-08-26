<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

use Stringable;

final readonly class CanonicalUrl implements Stringable
{
    public function __construct(
        public string $value,
        public string $scheme,
        public string $host,
        public ?int $port,
        public string $path,
        public ?string $query,
    ) {}

    public function hash(): string
    {
        return hash('sha256', $this->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
