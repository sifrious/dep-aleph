<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Envelope;

use InvalidArgumentException;

final readonly class ExtensionMetadata
{
    public const RESERVED_NAMESPACES = ['aleph', 'funes'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $namespace,
        public int $version,
        public array $data,
    ) {
        if (preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $namespace) !== 1) {
            throw new InvalidArgumentException(
                "Extension namespace [{$namespace}] must be a lowercase dotted slug."
            );
        }

        if (in_array(explode('.', $namespace)[0], self::RESERVED_NAMESPACES, true)) {
            throw new InvalidArgumentException(
                "Extension namespace [{$namespace}] uses a reserved root namespace."
            );
        }

        if ($version < 1) {
            throw new InvalidArgumentException('Extension version must be a positive integer.');
        }

        if (json_encode($data) === false) {
            throw new InvalidArgumentException(
                "Extension [{$namespace}] data must be JSON serialisable."
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'namespace' => $this->namespace,
            'version' => $this->version,
            'data' => $this->data,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            (string) ($payload['namespace'] ?? ''),
            (int) ($payload['version'] ?? 0),
            (array) ($payload['data'] ?? []),
        );
    }
}
