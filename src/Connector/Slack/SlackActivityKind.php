<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

enum SlackActivityKind: string
{
    case Workspace = 'workspace';
    case User = 'user';
    case Channel = 'channel';
    case Message = 'message';
    case File = 'file';
    case Link = 'link';
}
