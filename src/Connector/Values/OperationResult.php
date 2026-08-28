<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Values;

final readonly class OperationResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    private function __construct(
        public bool $successful,
        public int $records,
        public ?string $cursor,
        public bool $complete,
        public ?string $error,
        public array $metadata,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function completed(int $records = 0, array $metadata = []): self
    {
        return new self(true, $records, null, true, null, $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function partial(int $records, string $cursor, array $metadata = []): self
    {
        return new self(true, $records, $cursor, false, null, $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function failed(string $error, array $metadata = []): self
    {
        return new self(false, 0, null, false, $error, $metadata);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'successful' => $this->successful,
            'records' => $this->records,
            'cursor' => $this->cursor,
            'complete' => $this->complete,
            'error' => $this->error,
            'metadata' => $this->metadata,
        ];
    }
}
