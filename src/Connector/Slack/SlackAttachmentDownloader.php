<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

interface SlackAttachmentDownloader
{
    public function download(SlackAttachmentReference $reference, ?string $checkpoint): SlackAttachmentChunk;
}
