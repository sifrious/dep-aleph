<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Conversation;

use InvalidArgumentException;

final class AiConversationSources
{
    /** @var array<string, AiConversationSource> */
    private array $sources = [];

    public function register(AiConversationSource $source): void
    {
        $this->sources[$source->sourceReference()] = $source;
    }

    public function get(string $reference): AiConversationSource
    {
        return $this->sources[$reference]
            ?? throw new InvalidArgumentException("AI conversation source [{$reference}] is not registered.");
    }
}
