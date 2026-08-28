<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Conversation;

final readonly class AiConversationScan
{
    /** @param list<AiConversation> $conversations */
    public function __construct(
        public array $conversations,
        public string $sourceRevision,
        public ?string $cursor = null,
    ) {}
}
