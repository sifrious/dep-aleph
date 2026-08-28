<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

use InvalidArgumentException;

final class SlackActivitySources
{
    /** @var array<string, SlackActivitySource> */
    private array $sources = [];

    public function register(SlackActivitySource $source): void
    {
        $this->sources[$source->sourceReference()] = $source;
    }

    public function get(string $reference): SlackActivitySource
    {
        return $this->sources[$reference] ?? throw new InvalidArgumentException("Slack source [{$reference}] is not registered.");
    }
}
