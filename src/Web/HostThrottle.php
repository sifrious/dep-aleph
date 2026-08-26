<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

final class HostThrottle
{
    /**
     * @var array<string, float>
     */
    private array $lastRequestAt = [];

    public function __construct(private readonly Clock $clock) {}

    public function wait(string $host, float $delaySeconds): void
    {
        $last = $this->lastRequestAt[$host] ?? null;

        if ($last !== null) {
            $remaining = $delaySeconds - ($this->clock->now() - $last);

            if ($remaining > 0) {
                $this->clock->sleep($remaining);
            }
        }

        $this->lastRequestAt[$host] = $this->clock->now();
    }
}
