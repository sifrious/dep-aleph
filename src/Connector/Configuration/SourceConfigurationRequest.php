<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Configuration;

use DateTimeImmutable;

/**
 * What a host submits to declare a source. Values are provider-shaped bounds only;
 * credentials arrive as an opaque reference, never as material.
 */
final readonly class SourceConfigurationRequest
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(
        public string $sourceKey,
        public string $name,
        public array $values = [],
        public ?string $credentialReference = null,
        public ?string $owner = null,
        public ?DateTimeImmutable $submittedAt = null,
    ) {}

    public function submittedAt(): DateTimeImmutable
    {
        return $this->submittedAt ?? new DateTimeImmutable;
    }
}
