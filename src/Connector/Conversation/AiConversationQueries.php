<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Conversation;

final readonly class AiConversationQueries
{
    /**
     * @param  list<AiConversation>  $conversations
     * @return list<AiMessage>
     */
    public function chronology(array $conversations, ?string $author = null): array
    {
        $messages = [];

        foreach ($conversations as $conversation) {
            foreach ($conversation->messages as $message) {
                if ($author === null || $message->author === $author) {
                    $messages[] = $message;
                }
            }
        }

        usort($messages, static function (AiMessage $left, AiMessage $right): int {
            $leftTime = $left->occurredAt?->format('U.u');
            $rightTime = $right->occurredAt?->format('U.u');

            return [$leftTime ?? '', $left->ordinal, $left->providerId]
                <=> [$rightTime ?? '', $right->ordinal, $right->providerId];
        });

        return $messages;
    }

    /** @return list<AiMessage> */
    public function thread(AiConversation $conversation, string $messageId): array
    {
        $byId = [];

        foreach ($conversation->messages as $message) {
            $byId[$message->providerId] = $message;
        }

        $thread = [];
        $cursor = $byId[$messageId] ?? null;

        while ($cursor !== null) {
            array_unshift($thread, $cursor);
            $cursor = $cursor->parentProviderId !== null ? ($byId[$cursor->parentProviderId] ?? null) : null;
        }

        return $thread;
    }
}
