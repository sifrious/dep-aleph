<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

final class RobotsGroup
{
    /**
     * @var list<string>
     */
    public array $agents = [];

    /**
     * @var list<array{0: bool, 1: string}>
     */
    public array $rules = [];

    public ?float $crawlDelay = null;

    public function matches(string $agentToken): bool
    {
        foreach ($this->agents as $agent) {
            if ($agent !== '' && $agent !== '*' && str_starts_with($agentToken, $agent)) {
                return true;
            }
        }

        return false;
    }

    public function isWildcard(): bool
    {
        return in_array('*', $this->agents, true);
    }
}
