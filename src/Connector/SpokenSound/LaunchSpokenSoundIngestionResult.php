<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\SpokenSound;

final readonly class LaunchSpokenSoundIngestionResult
{
    /**
     * @param  list<string>  $acceptedReferences
     */
    public function __construct(
        public string $runId,
        public bool $replayed,
        public array $acceptedReferences,
    ) {}
}
