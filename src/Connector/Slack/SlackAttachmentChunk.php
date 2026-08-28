<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

final readonly class SlackAttachmentChunk
{
    public function __construct(public string $contents, public ?string $checkpoint, public bool $complete) {}
}
