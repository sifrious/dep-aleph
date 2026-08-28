<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Shell;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ShellCommandObservation
{
    /**
     * @param  list<string>  $argv
     * @param  array<string, mixed>  $rawMetadata
     */
    public function __construct(
        public ShellHistoryProvider $provider,
        public ShellCommandActor $actor,
        public string $sourceRecordId,
        public string $sourceRevision,
        public string $command,
        public array $argv,
        public ShellExecutionContext $context,
        public ?int $exitCode = null,
        public ?DateTimeImmutable $executedAt = null,
        public ?int $durationMilliseconds = null,
        public ?string $output = null,
        public array $rawMetadata = [],
    ) {
        if (trim($sourceRecordId) === '' || trim($sourceRevision) === '' || trim($command) === '') {
            throw new InvalidArgumentException('Shell command requires source record, revision, and command values.');
        }

        if ($durationMilliseconds !== null && $durationMilliseconds < 0) {
            throw new InvalidArgumentException('Shell command duration cannot be negative.');
        }
    }

    public function binary(): ?string
    {
        return $this->argv[0] ?? null;
    }
}
