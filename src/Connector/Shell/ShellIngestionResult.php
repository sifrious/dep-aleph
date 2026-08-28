<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Shell;

final readonly class ShellIngestionResult
{
    /**
     * @param  list<string>  $acceptedReferences
     */
    public function __construct(
        public int $commands,
        public int $redacted,
        public array $acceptedReferences,
    ) {}
}
