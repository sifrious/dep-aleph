<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Conversation;

enum AiMessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
    case System = 'system';
    case ToolUse = 'tool_use';
    case ToolResult = 'tool_result';
    case Summary = 'summary';
    case Unknown = 'unknown';
}
