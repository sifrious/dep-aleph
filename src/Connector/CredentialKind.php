<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector;

enum CredentialKind: string
{
    case ApiKey = 'api_key';
    case Basic = 'basic';
    case OAuth2 = 'oauth2';
    case Token = 'token';
    case Custom = 'custom';
}
