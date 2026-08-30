<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Image;

interface ImageConversionRecorder
{
    public function record(string $observationId, ImageConversion $conversion, string $sourceChecksum, string $runId): void;
}
