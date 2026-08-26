<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

use Illuminate\Support\Str;

final readonly class HostPolicy
{
    /**
     * @param  list<string>  $allowed
     * @param  list<string>  $restrictedTo
     */
    public function __construct(
        private array $allowed,
        private array $restrictedTo = [],
    ) {}

    public function allows(string $host): bool
    {
        if (! Str::is($this->allowed, $host)) {
            return false;
        }

        return $this->restrictedTo === [] || Str::is($this->restrictedTo, $host);
    }

    /**
     * @param  list<string>  $hosts
     */
    public function restrictTo(array $hosts): self
    {
        return new self($this->allowed, $hosts);
    }
}
