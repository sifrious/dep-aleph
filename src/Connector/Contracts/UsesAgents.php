<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Contracts;

use Sifrious\Aleph\Connector\Values\AgentTaskRequest;
use Sifrious\Aleph\Connector\Values\OperationResult;

interface UsesAgents
{
    public function runAgentTask(AgentTaskRequest $request): OperationResult;
}
