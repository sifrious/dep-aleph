<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Conversation;

enum AiProvider: string
{
    case Claude = 'claude';
    case Codex = 'codex';
    case Alternate = 'alternate';
}
