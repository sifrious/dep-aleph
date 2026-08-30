<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\NativePhpDesktop;

final readonly class LaunchNativePhpDesktopFreeformIngestionResult
{
    /**
     * @param  list<string>  $acceptedReferences
     */
    public function __construct(
        public string $runId,
        public bool $replayed,
        public string $artifactReference,
        public array $acceptedReferences,
    ) {}
}
