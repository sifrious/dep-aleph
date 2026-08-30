<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\ScoreTab;

interface ScoreTabDerivationRecorder
{
    public function record(string $observationId, ScoreTabModelDerivation $derivation, string $runId): void;
}
