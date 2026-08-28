<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector;

final readonly class ConfigurationField
{
    private function __construct(
        public string $name,
        public string $type,
        public bool $required,
        public bool $secret,
        public string $description,
    ) {}

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
        ];
    }
}
