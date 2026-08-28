<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

interface SlackSecretStore
{
    public function resolve(string $reference): ?SlackTokenSecret;

    public function refresh(string $reference): SlackSecretRotation;

    public function revoke(string $reference): void;
}
