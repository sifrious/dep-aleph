<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Conversation;

use InvalidArgumentException;

final readonly class AiConversation
{
    /**
     * @param  list<AiMessage>  $messages
     * @param  array<string, mixed>  $providerMetadata
     */
    public function __construct(
        public AiProvider $provider,
        public string $providerId,
        public string $sourceRevision,
        public string $rawReference,
        public array $messages,
        public array $providerMetadata = [],
    ) {
        if (trim($providerId) === '' || trim($sourceRevision) === '' || trim($rawReference) === '') {
            throw new InvalidArgumentException('AI conversation requires provider, revision, and raw references.');
        }

    }
}
