<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Values;

final readonly class AgentTaskRequest
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $sourceReference,
        public string $task,
        public array $context = [],
        public int $maxSteps = 1,
    ) {}
}
