<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector;

final readonly class RegisteredSourceAccount
{
    public function __construct(
        public ConnectorInstallation $installation,
        public ?ConnectorCredential $credential,
    ) {}
}
