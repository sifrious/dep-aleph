<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Conversation;

final readonly class AiConversationIngestionResult
{
    /** @param list<string> $acceptedReferences */
    public function __construct(
        public int $conversations,
        public int $messages,
        public array $acceptedReferences,
    ) {}
}
