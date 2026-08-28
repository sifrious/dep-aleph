<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

enum SlackRunScope: string
{
    case Channels = 'channels';
    case Channel = 'channel';
}
