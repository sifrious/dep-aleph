<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector;

final readonly class SourceAccountRegistration
{
    /**
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public string $label,
        public string $externalAccountId,
        public string $funesSourceAccountId,
        public array $settings = [],
        public ?string $owner = null,
        public ?CredentialInput $credential = null,
        public ?string $credentialsReference = null,
    ) {}
}
