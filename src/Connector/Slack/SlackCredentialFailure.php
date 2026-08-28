<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

use RuntimeException;

final class SlackCredentialFailure extends RuntimeException
{
    public function __construct(public readonly SlackCredentialState $state)
    {
        parent::__construct(match ($state) {
            SlackCredentialState::Missing => 'Slack credentials are missing.',
            SlackCredentialState::Expired => 'Slack credentials are expired.',
            SlackCredentialState::Revoked => 'Slack credentials are revoked.',
            SlackCredentialState::Active => 'Slack credentials could not be resolved.',
        });
    }
}
