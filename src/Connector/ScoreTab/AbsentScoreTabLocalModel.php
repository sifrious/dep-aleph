<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\ScoreTab;

final class AbsentScoreTabLocalModel implements ScoreTabLocalModel
{
    public function available(): bool
    {
        return false;
    }

    public function derive(string $contents, string $mediaType, string $name): ?ScoreTabModelDerivation
    {
        return null;
    }
}
