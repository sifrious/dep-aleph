<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Image;

/**
 * Mechanical image facts persisted beside raw bytes.
 * EXIF presence is always explicit: missing ≠ empty ≠ present.
 */
final readonly class ImageMetadata
{
    /**
     * @param  array<string, mixed>  $exifFields
     */
    public function __construct(
        public ?int $width,
        public ?int $height,
        public ?string $colorSpace,
        public ?string $capturedAt,
        public ?string $modifiedAt,
        public ImageExifPresence $exifPresence,
        public array $exifFields = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
            'color_space' => $this->colorSpace,
            'captured_at' => $this->capturedAt,
            'modified_at' => $this->modifiedAt,
            'exif' => [
                // Always present so callers can distinguish missing from empty.
                'presence' => $this->exifPresence->value,
                'fields' => $this->exifFields,
            ],
        ];
    }
}
