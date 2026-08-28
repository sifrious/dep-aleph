<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Shell;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ClaudeBashHistoryAdapter
{
    public function __construct(private ShellCommandTokenizer $tokenizer = new ShellCommandTokenizer) {}

    /**
     * @param  list<array<string, mixed>>  $calls
     * @return list<ShellCommandObservation>
     */
    public function adapt(array $calls, string $sourceRevision, ShellExecutionContext $context): array
    {
        $observations = [];

        foreach ($calls as $call) {
            $messageId = $call['message_id'] ?? null;
            $toolUseId = $call['tool_use_id'] ?? null;
            $command = trim((string) ($call['command'] ?? ''));

            if ((! is_string($messageId) && ! is_int($messageId)) || ! is_string($toolUseId)
                || $toolUseId === '' || $command === '') {
                throw new InvalidArgumentException('Claude Bash records require message, tool-use, and command identities.');
            }

            $observations[] = new ShellCommandObservation(
                ShellHistoryProvider::ClaudeBash,
                ShellCommandActor::Agent,
                $messageId.':'.$toolUseId,
                $sourceRevision,
                $command,
                $this->tokenizer->tokenize($command),
                $context,
                isset($call['exit_code']) ? (int) $call['exit_code'] : null,
                isset($call['executed_at']) ? new DateTimeImmutable((string) $call['executed_at']) : null,
                isset($call['duration_ms']) ? (int) $call['duration_ms'] : null,
                is_string($call['output'] ?? null) ? $call['output'] : null,
                ['message_id' => (string) $messageId, 'tool_use_id' => $toolUseId],
            );
        }

        return $observations;
    }
}
