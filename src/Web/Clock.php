<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

interface Clock
{
    public function now(): float;

    public function sleep(float $seconds): void;
}
