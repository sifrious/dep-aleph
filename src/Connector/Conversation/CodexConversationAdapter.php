<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Conversation;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CodexConversationAdapter
{
    /** @return list<AiConversation> */
    public function adapt(string $jsonLines, string $sourceRevision, string $rawReference): array
    {
        $conversationId = null;
        $metadata = [];
        $messages = [];
        $parentId = null;

        foreach (preg_split('/\R/', $jsonLines) ?: [] as $lineNumber => $line) {
            if (trim($line) === '') {
                continue;
            }

            $record = json_decode($line, true);

            if (! is_array($record)) {
                continue;
            }

            $payload = is_array($record['payload'] ?? null) ? $record['payload'] : [];

            if (($record['type'] ?? null) === 'session_meta') {
                $conversationId = $this->string($payload['id'] ?? null);
                $metadata = $payload;

                continue;
            }

            if (($record['type'] ?? null) !== 'response_item') {
                continue;
            }

            $message = $this->message($payload, $record, count($messages), $parentId, $rawReference, $lineNumber + 1);

            if ($message !== null) {
                $messages[] = $message;
                $parentId = $message->providerId;
            }
        }

        if ($conversationId === null) {
            throw new InvalidArgumentException('Codex history requires a session_meta id.');
        }

        return [new AiConversation(AiProvider::Codex, $conversationId, $sourceRevision, $rawReference, $messages, $metadata)];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $record
     */
    private function message(
        array $payload,
        array $record,
        int $ordinal,
        ?string $parentId,
        string $rawReference,
        int $lineNumber,
    ): ?AiMessage {
        $type = $this->string($payload['type'] ?? null);

        if (! in_array($type, ['message', 'function_call', 'function_call_output'], true)) {
            return null;
        }

        $role = match ($type) {
            'function_call' => AiMessageRole::ToolUse,
            'function_call_output' => AiMessageRole::ToolResult,
            default => match ($payload['role'] ?? null) {
                'user' => AiMessageRole::User,
                'assistant' => AiMessageRole::Assistant,
                'system', 'developer' => AiMessageRole::System,
                default => AiMessageRole::Unknown,
            },
        };
        $callId = $this->string($payload['call_id'] ?? null);
        $parts = match ($type) {
            'function_call' => [new AiMessagePart('tool_use', $this->string($payload['arguments'] ?? null), $callId, $payload)],
            'function_call_output' => [new AiMessagePart('tool_result', $this->string($payload['output'] ?? null), $callId, $payload)],
            default => $this->messageParts($payload['content'] ?? null),
        };
        $id = $this->string($payload['id'] ?? $callId)
            ?? substr(hash('sha256', json_encode($record, JSON_THROW_ON_ERROR)), 0, 32);

        return new AiMessage(
            $id,
            $this->string($payload['parent_id'] ?? null) ?? $parentId,
            $ordinal,
            $role,
            $this->string($payload['role'] ?? null) ?? ($type === 'function_call' ? 'assistant' : 'tool'),
            $parts,
            $this->timestamp($record['timestamp'] ?? null),
            $this->string($payload['thread_id'] ?? null),
            $this->string($payload['branch_id'] ?? null),
            (bool) ($payload['is_sidechain'] ?? false),
            $this->string($payload['agent_id'] ?? null),
            $record,
            $rawReference.'#L'.$lineNumber,
        );
    }

    /** @return list<AiMessagePart> */
    private function messageParts(mixed $content): array
    {
        if (! is_array($content)) {
            return [];
        }

        $parts = [];

        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = $this->string($block['type'] ?? null) ?? 'unknown';
            $parts[] = new AiMessagePart($type, $this->string($block['text'] ?? null), $this->string($block['call_id'] ?? null), $block);
        }

        return $parts;
    }

    private function timestamp(mixed $value): ?DateTimeImmutable
    {
        try {
            return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
