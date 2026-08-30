<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Handwriting;

use InvalidArgumentException;

/**
 * Derived handwriting OCR text (and optional boxes) from a free local/OSS model.
 * Stored as a new Funes extracted-representation version (MME-1438 / Digory MME-1228).
 * Never overwrites the authoritative handwritten image.
 */
final readonly class HandwritingOcrDerivation
{
    /**
     * @param  list<array<string, mixed>>|null  $boundingBoxes
     */
    public function __construct(
        public string $modelName,
        public string $modelVersion,
        public string $text,
        public ?array $boundingBoxes = null,
    ) {
        if (trim($modelName) === '' || trim($modelVersion) === '') {
            throw new InvalidArgumentException('A handwriting OCR derivation requires a model name and version.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function representation(): array
    {
        return array_filter([
            'kind' => 'handwriting_ocr',
            'text' => $this->text,
            'bounding_boxes' => $this->boundingBoxes,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
