<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Conversation;

use DateTimeImmutable;

final readonly class ClaudeConversationAdapter
{
    /** @return list<AiConversation> */
    public function adapt(string $jsonLines, string $sourceRevision, string $rawReference): array
    {
        $sessions = [];

        foreach (preg_split('/\R/', $jsonLines) ?: [] as $lineNumber => $line) {
            if (trim($line) === '') {
                continue;
            }

            $record = json_decode($line, true);

            if (! is_array($record) || ! is_string($record['sessionId'] ?? null) || trim($record['sessionId']) === '') {
                continue;
            }

            $sessionId = $record['sessionId'];
            if (! isset($sessions[$sessionId])) {
                $sessions[$sessionId] = ['metadata' => [], 'messages' => []];
            }
            $metadata = array_filter([
                'cwd' => $record['cwd'] ?? null,
                'entrypoint' => $record['entrypoint'] ?? null,
                'permission_mode' => $record['permissionMode'] ?? null,
                'version' => $record['version'] ?? null,
            ], static fn (mixed $value): bool => $value !== null);
            $sessions[$sessionId]['metadata'] = array_replace($sessions[$sessionId]['metadata'], $metadata);
            $message = $this->message($record, count($sessions[$sessionId]['messages']), $rawReference, $lineNumber + 1);

            if ($message !== null) {
                $sessions[$sessionId]['messages'][] = $message;
            }
        }

        $conversations = [];

        foreach ($sessions as $sessionId => $session) {
            $conversations[] = new AiConversation(
                AiProvider::Claude,
                $sessionId,
                $sourceRevision,
                $rawReference,
                $session['messages'],
                $session['metadata'],
            );
        }

        return $conversations;
    }

    /** @param array<string, mixed> $record */
    private function message(array $record, int $ordinal, string $rawReference, int $lineNumber): ?AiMessage
    {
        $type = is_string($record['type'] ?? null) ? $record['type'] : '';
        $message = $record['message'] ?? null;
        $parts = [];
        $role = match ($type) {
            'user' => AiMessageRole::User,
            'assistant' => AiMessageRole::Assistant,
            'system' => AiMessageRole::System,
            'summary' => AiMessageRole::Summary,
            default => null,
        };

        if ($role === null) {
            return null;
        }

        if ($type === 'summary') {
            $parts[] = new AiMessagePart('summary', $this->string($record['summary'] ?? null), null, $record);
        } elseif ($type === 'system' && ! is_array($message)) {
            $parts[] = new AiMessagePart('text', $this->string($message), null, ['message' => $message]);
        } elseif (is_array($message)) {
            $parts = $this->parts($message['content'] ?? null);
        } else {
            return null;
        }

        $blockTypes = array_map(static fn (AiMessagePart $part): string => $part->type, $parts);
        $role = match (true) {
            in_array('tool_result', $blockTypes, true) => AiMessageRole::ToolResult,
            in_array('tool_use', $blockTypes, true) && ! in_array('text', $blockTypes, true) => AiMessageRole::ToolUse,
            default => $role,
        };
        $providerId = $this->string($record['uuid'] ?? null)
            ?? substr(hash('sha256', json_encode($record, JSON_THROW_ON_ERROR)), 0, 32);

        return new AiMessage(
            $providerId,
            $this->string($record['parentUuid'] ?? null),
            $ordinal,
            $role,
            $this->author($message, $role),
            $parts,
            $this->timestamp($record['timestamp'] ?? null),
            $this->string($record['sessionId'] ?? null),
            $this->string($record['gitBranch'] ?? null),
            (bool) ($record['isSidechain'] ?? false),
            $this->string($record['agentId'] ?? null),
            $record,
            $rawReference.'#L'.$lineNumber,
        );
    }

    /** @return list<AiMessagePart> */
    private function parts(mixed $content): array
    {
        if (is_string($content)) {
            return [new AiMessagePart('text', $content, null, ['type' => 'text', 'text' => $content])];
        }

        if (! is_array($content)) {
            return [];
        }

        $parts = [];

        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = is_string($block['type'] ?? null) ? $block['type'] : 'unknown';
            $text = match ($type) {
                'text' => $this->string($block['text'] ?? null),
                'thinking' => $this->string($block['thinking'] ?? null),
                'tool_result' => $this->toolResultText($block['content'] ?? null),
                default => null,
            };
            $parts[] = new AiMessagePart(
                $type,
                $text,
                $this->string($block['id'] ?? $block['tool_use_id'] ?? null),
                $block,
            );
        }

        return $parts;
    }

    private function toolResultText(mixed $content): ?string
    {
        if (is_string($content)) {
            return $content;
        }

        if (! is_array($content)) {
            return null;
        }

        $texts = [];

        foreach ($content as $part) {
            if (is_array($part) && is_string($part['text'] ?? null)) {
                $texts[] = $part['text'];
            }
        }

        return $texts === [] ? null : implode("\n", $texts);
    }

    private function author(mixed $message, AiMessageRole $role): string
    {
        if (is_array($message) && is_string($message['role'] ?? null) && trim($message['role']) !== '') {
            return $message['role'];
        }

        return $role->value;
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
