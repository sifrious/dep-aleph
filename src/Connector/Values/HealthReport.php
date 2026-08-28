<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Values;

final readonly class HealthReport
{
    /**
     * @param  array<string, mixed>  $details
     */
    private function __construct(
        public bool $healthy,
        public string $summary,
        public array $details,
    ) {}

    /**
     * @param  array<string, mixed>  $details
     */
    public static function healthy(string $summary = 'connector healthy', array $details = []): self
    {
        return new self(true, $summary, $details);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function unhealthy(string $summary, array $details = []): self
    {
        return new self(false, $summary, $details);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['healthy' => $this->healthy, 'summary' => $this->summary, 'details' => $this->details];
    }
}
