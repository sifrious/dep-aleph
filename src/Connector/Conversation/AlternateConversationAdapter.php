<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Conversation;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AlternateConversationAdapter
{
    /**
     * @param  array<string, mixed>  $fixture
     * @return list<AiConversation>
     */
    public function adapt(array $fixture, string $sourceRevision, string $rawReference): array
    {
        $conversationId = $fixture['id'] ?? null;
        $records = $fixture['messages'] ?? null;

        if (! is_string($conversationId) || trim($conversationId) === '' || ! is_array($records)) {
            throw new InvalidArgumentException('Alternate conversation fixtures require an id and messages.');
        }

        $messages = [];

        foreach (array_values($records) as $ordinal => $record) {
            if (! is_array($record) || ! is_string($record['id'] ?? null) || ! is_string($record['author'] ?? null)) {
                throw new InvalidArgumentException('Alternate conversation messages require id and author values.');
            }

            $blocks = is_array($record['blocks'] ?? null) ? $record['blocks'] : [];
            $parts = [];

            foreach ($blocks as $block) {
                if (! is_array($block)) {
                    continue;
                }

                $parts[] = new AiMessagePart(
                    is_string($block['type'] ?? null) ? $block['type'] : 'unknown',
                    is_string($block['text'] ?? null) ? $block['text'] : null,
                    is_string($block['tool_call_id'] ?? null) ? $block['tool_call_id'] : null,
                    $block,
                );
            }

            $messages[] = new AiMessage(
                $record['id'],
                is_string($record['parent_id'] ?? null) ? $record['parent_id'] : null,
                $ordinal,
                AiMessageRole::tryFrom(is_string($record['role'] ?? null) ? $record['role'] : '') ?? AiMessageRole::Unknown,
                $record['author'],
                $parts,
                $this->timestamp($record['timestamp'] ?? null),
                is_string($record['thread_id'] ?? null) ? $record['thread_id'] : null,
                is_string($record['branch_id'] ?? null) ? $record['branch_id'] : null,
                (bool) ($record['sidechain'] ?? false),
                is_string($record['agent_id'] ?? null) ? $record['agent_id'] : null,
                $record,
                $rawReference.'#message='.$record['id'],
            );
        }

        $metadata = is_array($fixture['metadata'] ?? null) ? $fixture['metadata'] : [];

        return [new AiConversation(AiProvider::Alternate, $conversationId, $sourceRevision, $rawReference, $messages, $metadata)];
    }

    private function timestamp(mixed $value): ?DateTimeImmutable
    {
        try {
            return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
