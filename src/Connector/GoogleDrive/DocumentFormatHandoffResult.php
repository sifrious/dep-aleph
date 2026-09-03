<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GoogleDrive;

final readonly class DocumentFormatHandoffResult
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public string $status,
        public ?string $formatRunId = null,
        public array $details = [],
    ) {}

    public static function deferred(array $details = []): self
    {
        return new self('deferred', null, $details);
    }

    public static function launched(string $formatRunId, array $details = []): self
    {
        return new self('launched', $formatRunId, $details);
    }
}
