<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

final readonly class SlackAttachmentResult
{
    /** @param list<string> $handoffReferences */
    public function __construct(public bool $complete, public ?string $checkpoint, public array $handoffReferences) {}
}
