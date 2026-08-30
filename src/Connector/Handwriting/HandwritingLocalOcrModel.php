<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Handwriting;

/**
 * Optional free local/OSS handwriting OCR model.
 * Public package works with no model bound; missing models must not fail ingest.
 */
interface HandwritingLocalOcrModel
{
    public function available(): bool;

    public function recognize(string $contents, string $mediaType, string $name): ?HandwritingOcrDerivation;
}
