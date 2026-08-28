<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

interface SlackActivitySource
{
    public function sourceReference(): string;

    public function page(string $partition, SlackCheckpoint $checkpoint, int $limit): SlackActivityPage;
}
