<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

final readonly class SlackAttachmentReference
{
    public function __construct(public string $workspaceReference, public string $channelReference, public string $messageReference, public string $fileReference, public string $rawReference) {}

    public function historicalReference(): string
    {
        return 'digory:slack-attachment/'.$this->workspaceReference.'/'.$this->channelReference.'/'.$this->messageReference.'/'.$this->fileReference;
    }
}
