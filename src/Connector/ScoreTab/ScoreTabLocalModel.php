<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\ScoreTab;

/**
 * Optional free local/OSS model for deriving score/tab representations.
 * Public package works with no model bound; missing models must not fail ingest.
 */
interface ScoreTabLocalModel
{
    public function available(): bool;

    public function derive(string $contents, string $mediaType, string $name): ?ScoreTabModelDerivation;
}
