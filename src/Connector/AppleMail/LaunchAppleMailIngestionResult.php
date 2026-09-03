<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\AppleMail;

final readonly class LaunchAppleMailIngestionResult
{
    /**
     * @param  list<string>  $acceptedReferences
     * @param  list<array{part_id: string, reason: string}>  $attachmentFailures
     */
    public function __construct(
        public string $runId,
        public bool $replayed,
        public string $messageArtifactReference,
        public array $acceptedReferences,
        public array $attachmentFailures = [],
    ) {}
}
