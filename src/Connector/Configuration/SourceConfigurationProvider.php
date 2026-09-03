<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Configuration;

use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\CredentialKind;

/**
 * The provider-neutral port a connector implements to declare what a source of its kind is:
 * its fields, the bounds those fields must satisfy, and whether it needs a credential.
 */
interface SourceConfigurationProvider
{
    /**
     * The reference prefix for sources of this kind — `web` yields `web:ahsd`.
     */
    public function sourceKind(): string;

    public function schema(): ConfigurationSchema;

    /**
     * The credential a source of this kind requires, or null when it needs none.
     */
    public function credentialKind(): ?CredentialKind;

    /**
     * Translate submitted provider-shaped values into the neutral bounded record.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     *
     * @throws SourceConfigurationRejected when a value falls outside the source's bounds
     */
    public function bound(array $values): array;
}
