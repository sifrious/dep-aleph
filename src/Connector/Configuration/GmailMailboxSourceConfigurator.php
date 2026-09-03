<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Configuration;

final class GmailMailboxSourceConfigurator extends AbstractSourceConfigurator
{
    protected function provider(): SourceConfigurationProvider
    {
        return new GmailMailboxConfigurationAdapter;
    }
}
