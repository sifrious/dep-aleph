<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Handwriting;

final class AbsentHandwritingLocalOcrModel implements HandwritingLocalOcrModel
{
    public function available(): bool
    {
        return false;
    }

    public function recognize(string $contents, string $mediaType, string $name): ?HandwritingOcrDerivation
    {
        return null;
    }
}
