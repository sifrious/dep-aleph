<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use stdClass;

final readonly class SlackCredentials
{
    public function __construct(
        private ConnectionInterface $connection,
        private ConnectorInstallations $installations,
    ) {}

    public function migrate(LandingSlackTokenRecord $record): SlackCredential
    {
        $existing = $this->byLegacyReference('landing:slack-token/'.$record->legacyId);

        if ($existing !== null) {
            return $existing;
        }

        if ($this->installations->find($record->sourceInstallationId) === null) {
            throw new InvalidArgumentException('The Slack source installation does not exist.');
        }

        if (trim($record->legacyId) === '' || trim($record->workspaceReference) === '') {
            throw new InvalidArgumentException('Slack migration requires legacy and workspace references.');
        }

        if ($record->secretReference !== null && trim($record->secretReference) === '') {
            throw new InvalidArgumentException('Slack secret references must be null or non-empty.');
        }

        if (count($record->scopes) !== count(array_unique($record->scopes)) || array_any($record->scopes, fn (string $scope): bool => trim($scope) === '')) {
            throw new InvalidArgumentException('Slack scopes must be unique non-empty values.');
        }

        $state = $this->state($record);
        $this->table()->insert([
            'id' => (string) Str::ulid(),
            'source_installation_id' => $record->sourceInstallationId,
            'workspace_reference' => $record->workspaceReference,
            'account_reference' => $record->accountReference,
            'secret_reference' => $record->secretReference,
            'scopes' => $record->scopes === [] ? null : json_encode($record->scopes, JSON_THROW_ON_ERROR),
            'state' => $state->value,
            'expires_at' => $record->expiresAt,
            'revoked_at' => $record->revokedAt,
            'refreshed_at' => $record->refreshedAt,
            'legacy_reference' => 'landing:slack-token/'.$record->legacyId,
            'created_at' => $record->createdAt,
            'updated_at' => $record->updatedAt,
        ]);

        return $this->byLegacyReference('landing:slack-token/'.$record->legacyId)
            ?? throw new InvalidArgumentException('The migrated Slack credential could not be read back.');
    }

    public function forInstallation(string $sourceInstallationId): ?SlackCredential
    {
        $row = $this->table()->where('source_installation_id', $sourceInstallationId)->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    public function refresh(string $id, SlackSecretRotation $rotation, DateTimeImmutable $refreshedAt): SlackCredential
    {
        $this->table()->where('id', $id)->update([
            'scopes' => $rotation->scopes === [] ? null : json_encode($rotation->scopes, JSON_THROW_ON_ERROR),
            'state' => SlackCredentialState::Active->value,
            'expires_at' => $rotation->expiresAt,
            'revoked_at' => null,
            'refreshed_at' => $refreshedAt,
            'updated_at' => $refreshedAt,
        ]);

        return $this->find($id) ?? throw new InvalidArgumentException('The refreshed Slack credential does not exist.');
    }

    public function revoke(string $id, DateTimeImmutable $revokedAt): SlackCredential
    {
        $this->table()->where('id', $id)->update([
            'state' => SlackCredentialState::Revoked->value,
            'revoked_at' => $revokedAt,
            'updated_at' => $revokedAt,
        ]);

        return $this->find($id) ?? throw new InvalidArgumentException('The revoked Slack credential does not exist.');
    }

    private function find(string $id): ?SlackCredential
    {
        $row = $this->table()->where('id', $id)->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    private function byLegacyReference(string $reference): ?SlackCredential
    {
        $row = $this->table()->where('legacy_reference', $reference)->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    private function state(LandingSlackTokenRecord $record): SlackCredentialState
    {
        if ($record->secretReference === null) {
            return SlackCredentialState::Missing;
        }

        if ($record->revokedAt !== null) {
            return SlackCredentialState::Revoked;
        }

        if ($record->expiresAt !== null && $record->expiresAt <= $record->updatedAt) {
            return SlackCredentialState::Expired;
        }

        return SlackCredentialState::Active;
    }

    private function hydrate(stdClass $row): SlackCredential
    {
        $scopes = $row->scopes === null ? [] : json_decode((string) $row->scopes, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($scopes)) {
            throw new JsonException('Stored Slack scopes have an invalid shape.');
        }

        return new SlackCredential(
            (string) $row->id,
            (string) $row->source_installation_id,
            (string) $row->workspace_reference,
            $row->account_reference === null ? null : (string) $row->account_reference,
            $row->secret_reference === null ? null : (string) $row->secret_reference,
            array_values(array_map(strval(...), $scopes)),
            SlackCredentialState::from((string) $row->state),
            $row->expires_at === null ? null : new DateTimeImmutable((string) $row->expires_at),
            $row->revoked_at === null ? null : new DateTimeImmutable((string) $row->revoked_at),
            $row->refreshed_at === null ? null : new DateTimeImmutable((string) $row->refreshed_at),
            (string) $row->legacy_reference,
            new DateTimeImmutable((string) $row->created_at),
            new DateTimeImmutable((string) $row->updated_at),
        );
    }

    private function table(): Builder
    {
        return $this->connection->table('aleph_slack_credentials');
    }
}
