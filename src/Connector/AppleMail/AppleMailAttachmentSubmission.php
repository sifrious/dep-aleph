<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\AppleMail;

final readonly class AppleMailAttachmentSubmission
{
    public function __construct(
        public string $sourceReference,
        public string $sourceInstallationId,
        public string $runId,
        public string $messageArtifactReference,
        public string $rfcMessageId,
        public string $partId,
        public string $artifactReference,
        public string $mediaType,
        public string $contents,
        public string $checksum,
        public int $bytes,
        public ?string $filename,
        public bool $inline,
        public ?string $contentId,
    ) {}
}
