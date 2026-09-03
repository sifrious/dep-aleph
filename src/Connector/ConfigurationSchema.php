<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector;

use Countable;

final readonly class ConfigurationSchema implements Countable
{
    /** @var list<ConfigurationField> */
    public array $fields;

    public function __construct(ConfigurationField ...$fields)
    {
        $this->fields = array_values($fields);
    }

    public static function none(): self
    {
        return new self;
    }

    public function count(): int
    {
        return count($this->fields);
    }

    public function has(string $name): bool
    {
        return $this->field($name) !== null;
    }

    public function field(string $name): ?ConfigurationField
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return $this->namesWhere(static fn (ConfigurationField $field): bool => true);
    }

    /**
     * Every non-secret input resolved from the environment, falling back to its declared default.
     *
     * @param  null|callable(string): (string|null)  $reader
     * @return array<string, mixed>
     */
    public function resolveDefaults(?callable $reader = null): array
    {
        $defaults = [];

        foreach ($this->fields as $field) {
            if ($field->secret) {
                continue;
            }

            $value = $field->resolveDefault($reader);

            if ($value !== null) {
                $defaults[$field->name] = $value;
            }
        }

        return $defaults;
    }

    /**
     * @return list<string>
     */
    public function required(): array
    {
        return $this->namesWhere(static fn (ConfigurationField $field): bool => $field->required);
    }

    /**
     * @return list<string>
     */
    public function secrets(): array
    {
        return $this->namesWhere(static fn (ConfigurationField $field): bool => $field->secret);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (ConfigurationField $field): array => $field->toArray(),
            $this->fields,
        );
    }

    /**
     * @param  callable(ConfigurationField): bool  $predicate
     * @return list<string>
     */
    private function namesWhere(callable $predicate): array
    {
        return array_values(array_map(
            static fn (ConfigurationField $field): string => $field->name,
            array_filter($this->fields, $predicate),
        ));
    }
}
