<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Assertion;

interface HistoricalAssertionAdapter
{
    public function provider(): string;

    public function normalize(ProviderAssertionInput $input): AssertionNormalization;
}
