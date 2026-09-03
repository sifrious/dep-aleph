<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Handwriting;

interface HandwritingOcrDerivationRecorder
{
    public function record(string $observationId, HandwritingOcrDerivation $derivation, string $runId): void;
}
