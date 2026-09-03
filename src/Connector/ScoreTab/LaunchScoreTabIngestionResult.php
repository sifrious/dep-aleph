<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\ScoreTab;

final readonly class LaunchScoreTabIngestionResult
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
