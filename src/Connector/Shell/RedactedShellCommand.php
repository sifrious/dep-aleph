<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Shell;

final readonly class RedactedShellCommand
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public string $command,
        public ?string $output,
        public string $originalCommandHash,
        public RedactionDecision $decision,
        public array $reasons,
    ) {}
}
