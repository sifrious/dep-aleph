<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\AppleMail;

final readonly class AppleMailMessageSubmission
{
    /**
     * @param  array<string, mixed>  $message
     * @param  list<array<string, mixed>>  $attachmentSummaries
     */
    public function __construct(
        public string $sourceReference,
        public string $sourceInstallationId,
        public string $runId,
        public string $artifactReference,
        public string $rfcMessageId,
        public string $payload,
        public array $message,
        public array $attachmentSummaries,
    ) {}
}
