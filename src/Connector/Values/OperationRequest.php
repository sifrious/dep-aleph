<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Values;

final readonly class OperationRequest
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public string $sourceReference,
        public array $parameters = [],
        public ?string $cursor = null,
    ) {}

    public function withCursor(?string $cursor): self
    {
        return new self($this->sourceReference, $this->parameters, $cursor);
    }

    public function parameter(string $key, mixed $default = null): mixed
    {
        return $this->parameters[$key] ?? $default;
    }
}
