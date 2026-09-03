<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

use DateTimeImmutable;
use Sifrious\Aleph\Connector\ConnectorInstallations;

final readonly class SlackCredentialBroker
{
    public function __construct(
        private SlackCredentials $credentials,
        private SlackSecretStore $secrets,
        private ?ConnectorInstallations $installations = null,
    ) {}

    public function accessToken(string $sourceInstallationId, DateTimeImmutable $at): SlackTokenSecret
    {
        $credential = $this->credentials->forInstallation($sourceInstallationId);

        if ($credential === null) {
            $reference = $this->installations?->find($sourceInstallationId)?->credentialsReference;

            if ($reference === null || $reference === '') {
                throw new SlackCredentialFailure(SlackCredentialState::Missing);
            }

            return $this->secrets->resolve($reference)
                ?? throw new SlackCredentialFailure(SlackCredentialState::Missing);
        }

        $state = $credential->stateAt($at);

        if ($state !== SlackCredentialState::Active || $credential->secretReference === null) {
            throw new SlackCredentialFailure($state);
        }

        return $this->secrets->resolve($credential->secretReference)
            ?? throw new SlackCredentialFailure(SlackCredentialState::Missing);
    }

    public function refresh(string $sourceInstallationId, DateTimeImmutable $at): SlackTokenSecret
    {
        $credential = $this->credentials->forInstallation($sourceInstallationId);

        if ($credential === null || $credential->secretReference === null) {
            throw new SlackCredentialFailure(SlackCredentialState::Missing);
        }

        if ($credential->stateAt($at) === SlackCredentialState::Revoked) {
            throw new SlackCredentialFailure(SlackCredentialState::Revoked);
        }

        $rotation = $this->secrets->refresh($credential->secretReference);
        $this->credentials->refresh($credential->id, $rotation, $at);

        return $rotation->accessToken;
    }

    public function revoke(string $sourceInstallationId, DateTimeImmutable $at): void
    {
        $credential = $this->credentials->forInstallation($sourceInstallationId);

        if ($credential === null || $credential->secretReference === null) {
            throw new SlackCredentialFailure(SlackCredentialState::Missing);
        }

        $this->secrets->revoke($credential->secretReference);
        $this->credentials->revoke($credential->id, $at);
    }
}
