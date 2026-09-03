<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Email;

interface GmailTokenResolver
{
    public function resolve(string $sourceInstallationId): GmailTokenSecret;
}
