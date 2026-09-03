<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Configuration;

use InvalidArgumentException;
use Sifrious\Aleph\Connector\CredentialKind;

final class SourceConfigurationRejected extends InvalidArgumentException
{
    public const UNKNOWN_INPUT = 'unknown_input';

    public const INLINE_SECRET = 'inline_secret';

    public const MISSING_INPUT = 'missing_input';

    public const MISSING_CREDENTIAL = 'missing_credential';

    public const UNEXPECTED_CREDENTIAL = 'unexpected_credential';

    public const OUT_OF_BOUNDS = 'out_of_bounds';

    public const INVALID_SOURCE_KEY = 'invalid_source_key';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function unknownInput(string $name, string $kind): self
    {
        return new self(self::UNKNOWN_INPUT, "Input [{$name}] is not declared by the [{$kind}] configuration schema.");
    }

    public static function inlineSecret(string $name): self
    {
        return new self(self::INLINE_SECRET, "Input [{$name}] is a credential and must be supplied as an opaque reference, not a value.");
    }

    public static function missingInput(string $name): self
    {
        return new self(self::MISSING_INPUT, "Input [{$name}] is required and has no environment value or default.");
    }

    public static function missingCredential(string $kind, CredentialKind $expected): self
    {
        return new self(self::MISSING_CREDENTIAL, "A [{$kind}] source requires a [{$expected->value}] credential reference.");
    }

    public static function unexpectedCredential(string $kind): self
    {
        return new self(self::UNEXPECTED_CREDENTIAL, "A [{$kind}] source declares no credential requirement.");
    }

    public static function outOfBounds(string $message): self
    {
        return new self(self::OUT_OF_BOUNDS, $message);
    }

    public static function invalidSourceKey(string $key): self
    {
        return new self(self::INVALID_SOURCE_KEY, "Source key [{$key}] must be a lowercase slug.");
    }
}
