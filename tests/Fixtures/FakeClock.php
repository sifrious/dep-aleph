<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Tests\Fixtures;

use Sifrious\Aleph\Web\Clock;

final class FakeClock implements Clock
{
    private float $now = 0.0;

    /**
     * @var list<float>
     */
    public array $slept = [];

    public function now(): float
    {
        return $this->now;
    }

    public function sleep(float $seconds): void
    {
        $this->slept[] = $seconds;
        $this->now += $seconds;
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }
}
