<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

final readonly class SlackCheckpoint
{
    public function __construct(public ?string $cursor, public ?string $highWater) {}

    public function encode(): string
    {
        return json_encode(['cursor' => $this->cursor, 'high_water' => $this->highWater], JSON_THROW_ON_ERROR);
    }

    public static function decode(?string $value): self
    {
        if ($value === null || $value === '') {
            return new self(null, null);
        }

        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        return new self(
            is_string($decoded['cursor'] ?? null) ? $decoded['cursor'] : null,
            is_string($decoded['high_water'] ?? null) ? $decoded['high_water'] : null,
        );
    }
}
