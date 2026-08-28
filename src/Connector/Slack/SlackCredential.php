<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

use DateTimeImmutable;

final readonly class SlackCredential
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $id,
        public string $sourceInstallationId,
        public string $workspaceReference,
        public ?string $accountReference,
        public ?string $secretReference,
        public array $scopes,
        public SlackCredentialState $state,
        public ?DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $revokedAt,
        public ?DateTimeImmutable $refreshedAt,
        public string $legacyReference,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    public function stateAt(DateTimeImmutable $at): SlackCredentialState
    {
        if ($this->secretReference === null) {
            return SlackCredentialState::Missing;
        }

        if ($this->state === SlackCredentialState::Revoked || $this->revokedAt !== null) {
            return SlackCredentialState::Revoked;
        }

        if ($this->expiresAt !== null && $this->expiresAt <= $at) {
            return SlackCredentialState::Expired;
        }

        return $this->state;
    }

    /** @return array<string, mixed> */
    public function metadata(DateTimeImmutable $at): array
    {
        return [
            'id' => $this->id,
            'source_installation_id' => $this->sourceInstallationId,
            'workspace_reference' => $this->workspaceReference,
            'account_reference' => $this->accountReference,
            'credential_reference' => $this->secretReference,
            'scopes' => $this->scopes,
            'state' => $this->stateAt($at)->value,
            'expires_at' => $this->expiresAt?->format(DATE_ATOM),
            'revoked_at' => $this->revokedAt?->format(DATE_ATOM),
            'refreshed_at' => $this->refreshedAt?->format(DATE_ATOM),
            'legacy_reference' => $this->legacyReference,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
        ];
    }
}
