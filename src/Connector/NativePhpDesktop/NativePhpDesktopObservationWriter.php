<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\NativePhpDesktop;

interface NativePhpDesktopObservationWriter
{
    public function write(NativePhpDesktopFreeformSubmission $submission, string $attemptId): string;
}
