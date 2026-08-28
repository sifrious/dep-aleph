<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Shell;

final readonly class ShellHistoryScan
{
    /**
     * @param  list<ShellCommandObservation>  $commands
     */
    public function __construct(
        public array $commands,
        public string $sourceRevision,
        public ?string $cursor,
    ) {}
}
