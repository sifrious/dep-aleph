<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Shell;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AtuinHistoryAdapter
{
    public function __construct(private ShellCommandTokenizer $tokenizer = new ShellCommandTokenizer) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<ShellCommandObservation>
     */
    public function adapt(array $rows, string $sourceRevision, ShellExecutionContext $fallback): array
    {
        $observations = [];

        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $command = trim((string) ($row['command'] ?? ''));

            if ((! is_string($id) && ! is_int($id)) || $command === '') {
                throw new InvalidArgumentException('Atuin history records require id and command values.');
            }

            $timestamp = (int) ($row['timestamp'] ?? 0);
            $duration = isset($row['duration']) ? intdiv((int) $row['duration'], 1_000_000) : null;
            $context = new ShellExecutionContext(
                (string) ($row['hostname'] ?? $fallback->host),
                $fallback->user,
                $fallback->shell,
                is_string($row['cwd'] ?? null) ? $row['cwd'] : $fallback->cwd,
                is_string($row['session'] ?? null) ? $row['session'] : $fallback->session,
                $fallback->environmentReference,
            );
            $observations[] = new ShellCommandObservation(
                ShellHistoryProvider::Atuin,
                ShellCommandActor::Human,
                (string) $id,
                $sourceRevision,
                $command,
                $this->tokenizer->tokenize($command),
                $context,
                isset($row['exit']) ? (int) $row['exit'] : null,
                $timestamp > 0 ? new DateTimeImmutable('@'.intdiv($timestamp, 1_000_000_000)) : null,
                $duration,
                rawMetadata: ['atuin_id' => (string) $id],
            );
        }

        return $observations;
    }
}
