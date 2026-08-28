<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

interface SlackAttachmentHandoff
{
    public function accept(string $historicalReference, string $contents, string $rawReference): string;
}
