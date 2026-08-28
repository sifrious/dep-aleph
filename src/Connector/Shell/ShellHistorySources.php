<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Shell;

use InvalidArgumentException;

final class ShellHistorySources
{
    /** @var array<string, ShellHistorySource> */
    private array $sources = [];

    public function register(ShellHistorySource $source): void
    {
        $this->sources[$source->sourceReference()] = $source;
    }

    public function get(string $reference): ShellHistorySource
    {
        return $this->sources[$reference]
            ?? throw new InvalidArgumentException("Shell history source [{$reference}] is not registered.");
    }
}
