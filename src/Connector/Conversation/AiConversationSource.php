<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Conversation;

interface AiConversationSource
{
    public function sourceReference(): string;

    public function scan(?string $cursor): AiConversationScan;
}
