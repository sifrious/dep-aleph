<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector;

use InvalidArgumentException;

final readonly class ConfigurationField
{
    private function __construct(
        public string $name,
        public string $type,
        public bool $required,
        public bool $secret,
        public string $description,
        public ?string $envKey = null,
        public mixed $default = null,
    ) {
        if ($secret && ($envKey !== null || $default !== null)) {
            throw new InvalidArgumentException(
                "Configuration field [{$name}] is secret and must not carry an environment key or default value."
            );
        }
    }

    public static function text(string $name, string $description = '', bool $required = true): self
    {
        return new self($name, 'string', $required, false, $description);
    }

    public static function number(string $name, string $description = '', bool $required = true): self
    {
        return new self($name, 'integer', $required, false, $description);
    }

    public static function boolean(string $name, string $description = '', bool $required = false): self
    {
        return new self($name, 'boolean', $required, false, $description);
    }

    public static function list(string $name, string $description = '', bool $required = false): self
    {
        return new self($name, 'array', $required, false, $description);
    }

    public static function secret(string $name, string $description = '', bool $required = true): self
    {
        return new self($name, 'string', $required, true, $description);
    }

    /**
     * Declare the environment variable a host may read this input from.
     */
    public function fromEnv(string $envKey): self
    {
        return new self($this->name, $this->type, $this->required, $this->secret, $this->description, $envKey, $this->default);
    }

    /**
     * Declare the value used when neither the submission nor the environment supplies one.
     */
    public function withDefault(mixed $default): self
    {
        return new self($this->name, $this->type, $this->required, $this->secret, $this->description, $this->envKey, $default);
    }

    public function hasDefault(): bool
    {
        return $this->default !== null;
    }

    /**
     * The environment value cast to the declared type, or the declared default.
     *
     * @param  null|callable(string): (string|null)  $reader
     */
    public function resolveDefault(?callable $reader = null): mixed
    {
        if ($this->envKey === null) {
            return $this->default;
        }

        $raw = ($reader ?? self::environment(...))($this->envKey);

        return $raw === null || $raw === '' ? $this->default : $this->cast($raw);
    }

    /**
     * What a host should expect when no value is supplied anywhere.
     */
    public function absentBehavior(): string
    {
        if ($this->required) {
            return 'rejected';
        }

        return $this->hasDefault() ? 'default applied' : 'omitted';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'required' => $this->required,
            'secret' => $this->secret,
            'description' => $this->description,
            'env' => $this->envKey,
            'default' => $this->default,
            'absent' => $this->absentBehavior(),
        ];
    }

    private function cast(string $raw): mixed
    {
        return match ($this->type) {
            'integer' => (int) $raw,
            'boolean' => filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
            'array' => array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $value): bool => $value !== '')),
            default => $raw,
        };
    }

    private static function environment(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
