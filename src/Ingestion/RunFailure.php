<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

final readonly class RunFailure
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public string $kind,
        public string $message,
        public bool $retryable,
        public array $details = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'message' => $this->message,
            'retryable' => $this->retryable,
            'details' => $this->details,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            kind: (string) ($data['kind'] ?? 'unknown'),
            message: (string) ($data['message'] ?? ''),
            retryable: (bool) ($data['retryable'] ?? false),
            details: is_array($data['details'] ?? null) ? $data['details'] : [],
        );
    }
}
