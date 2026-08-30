<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\NativePhpDesktop;

final readonly class NativePhpDesktopFreeformSubmission
{
    public function __construct(
        public string $sourceReference,
        public string $sourceInstallationId,
        public string $runId,
        public string $artifactReference,
        public string $body,
        public string $sha256,
        public int $bytes,
    ) {}
}
