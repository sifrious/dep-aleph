<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Image;

/**
 * Explicit same-pixels format conversion.
 * Produces a new Funes extracted-representation version; does not overwrite the original artifact.
 */
final readonly class ConvertImageFormat
{
    public function __construct(
        private ImageConverter $converter,
        private ImageConversionRecorder $conversions,
    ) {}

    public function convert(ConvertImageFormatRequest $request): ConvertImageFormatResult
    {
        $sourceChecksum = hash('sha256', $request->sourceContents);
        $conversion = $this->converter->convert(
            $request->sourceContents,
            $request->sourceMediaType,
            $request->targetFormat,
        );
        $this->conversions->record(
            $request->observationId,
            $conversion,
            $sourceChecksum,
            $request->runId,
        );

        return new ConvertImageFormatResult($request->observationId, $conversion);
    }
}
