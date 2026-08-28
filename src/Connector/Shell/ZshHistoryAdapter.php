<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Shell;

use DateTimeImmutable;

final readonly class ZshHistoryAdapter
{
    public function __construct(private ShellCommandTokenizer $tokenizer = new ShellCommandTokenizer) {}

    /**
     * @return list<ShellCommandObservation>
     */
    public function adapt(string $contents, string $sourceRevision, ShellExecutionContext $context): array
    {
        $observations = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $ordinal => $raw) {
            $executedAt = null;
            $command = trim($raw);

            if (preg_match('/^:\s*(\d+):\d+;(.*)$/s', $command, $match) === 1) {
                $executedAt = new DateTimeImmutable('@'.$match[1]);
                $command = trim($match[2]);
            }

            if ($command === '') {
                continue;
            }

            $observations[] = new ShellCommandObservation(
                ShellHistoryProvider::Zsh,
                ShellCommandActor::Human,
                $ordinal.':'.substr(hash('sha256', $command), 0, 16),
                $sourceRevision,
                $command,
                $this->tokenizer->tokenize($command),
                $context,
                executedAt: $executedAt,
                rawMetadata: ['file_ordinal' => $ordinal],
            );
        }

        return $observations;
    }
}
