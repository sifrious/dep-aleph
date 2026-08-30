<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\AppleMail;

use InvalidArgumentException;

final readonly class LocalAppleMailAttachment
{
    public function __construct(
        public string $partId,
        public ?string $filename = null,
        public ?string $contents = null,
        public bool $inline = false,
        public ?string $contentId = null,
        public ?string $declaredMediaType = null,
    ) {
        if (trim($partId) === '') {
            throw new InvalidArgumentException('Apple Mail attachments require a stable part id.');
        }
    }

    public function hasBytes(): bool
    {
        return $this->contents !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'part_id' => $this->partId,
            'filename' => $this->filename,
            'inline' => $this->inline,
            'content_id' => $this->contentId,
            'declared_media_type' => $this->declaredMediaType,
            'contents_base64' => $this->contents === null ? null : base64_encode($this->contents),
            'bytes_present' => $this->hasBytes(),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $partId = is_string($row['part_id'] ?? null) ? $row['part_id'] : '';
        $encoded = $row['contents_base64'] ?? null;
        $contents = null;

        if (is_string($encoded)) {
            $decoded = base64_decode($encoded, true);

            if (! is_string($decoded)) {
                throw new InvalidArgumentException('Apple Mail attachment contained invalid base64 content.');
            }

            $contents = $decoded;
        }

        return new self(
            partId: $partId,
            filename: is_string($row['filename'] ?? null) ? $row['filename'] : null,
            contents: $contents,
            inline: ($row['inline'] ?? false) === true,
            contentId: is_string($row['content_id'] ?? null) ? $row['content_id'] : null,
            declaredMediaType: is_string($row['declared_media_type'] ?? null) ? $row['declared_media_type'] : null,
        );
    }
}
