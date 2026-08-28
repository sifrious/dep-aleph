<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

enum SlackCredentialState: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Missing = 'missing';
}
