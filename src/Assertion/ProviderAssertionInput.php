<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Assertion;

use Sifrious\AuthorizationContract\AuthorizationContext;
use Sifrious\ReferenceContract\CrossPackageReference;

final readonly class ProviderAssertionInput
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $provider,
        public array $payload,
        public CrossPackageReference $rawSource,
        public AuthorizationContext $authorization,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]*$/', $provider) !== 1) {
            throw new AssertionNormalizationException('Assertion providers require a stable lowercase identifier.');
        }
    }
}
