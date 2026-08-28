<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

final readonly class AcquireSlackAttachment
{
    public function __construct(private SlackAttachmentDownloader $downloader, private SlackAttachmentHandoff $handoff) {}

    public function acquire(SlackAttachmentReference $reference, ?string $checkpoint = null, int $maxChunks = 10): SlackAttachmentResult
    {
        $accepted = [];
        $complete = false;
        for ($chunkNumber = 0; $chunkNumber < $maxChunks && ! $complete; $chunkNumber++) {
            $chunk = $this->downloader->download($reference, $checkpoint);
            $accepted[] = $this->handoff->accept($reference->historicalReference(), $chunk->contents, $reference->rawReference);
            $checkpoint = $chunk->checkpoint;
            $complete = $chunk->complete;
        }

        return new SlackAttachmentResult($complete, $checkpoint, array_values(array_unique($accepted)));
    }
}
