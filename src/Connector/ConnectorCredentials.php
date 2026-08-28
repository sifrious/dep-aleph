<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector;

use DateTimeImmutable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use stdClass;

final readonly class ConnectorCredentials
{
    public function __construct(
        private ConnectionInterface $connection,
        private Encrypter $encrypter,
        private ConnectorInstallations $installations,
    ) {}

    public function create(string $sourceInstallationId, CredentialInput $input): ConnectorCredential
    {
        if ($this->installations->find($sourceInstallationId) === null) {
            throw new InvalidArgumentException('The source installation does not exist.');
        }

        $this->validate($input);
        $id = (string) Str::ulid();
        $reference = 'aleph://credentials/'.$id;
        $now = Carbon::now();
        $this->table()->insert([
            'id' => $id,
            'source_installation_id' => $sourceInstallationId,
            'reference' => $reference,
            'kind' => $input->kind->value,
            'material' => $this->encrypter->encrypt($input->material),
            'scopes' => $input->scopes === [] ? null : json_encode($input->scopes, JSON_THROW_ON_ERROR),
            'refresh_metadata' => $input->refreshMetadata === [] ? null : $this->encrypter->encrypt($input->refreshMetadata),
            'expires_at' => $input->expiresAt,
            'refreshed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->installations->bindCredentialsReference($sourceInstallationId, $reference);

        return $this->find($reference) ?? throw new InvalidArgumentException('The connector credential could not be read back.');
    }

    public function find(string $reference): ?ConnectorCredential
    {
        $row = $this->table()->where('reference', $reference)->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    public function forInstallation(string $sourceInstallationId): ?ConnectorCredential
    {
        $row = $this->table()->where('source_installation_id', $sourceInstallationId)->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    public function refresh(string $reference, CredentialInput $input, DateTimeImmutable $refreshedAt): ConnectorCredential
    {
        $current = $this->find($reference);

        if ($current === null) {
            throw new InvalidArgumentException('The connector credential does not exist.');
        }

        $this->validate($input);
        $this->table()->where('reference', $reference)->update([
            'kind' => $input->kind->value,
            'material' => $this->encrypter->encrypt($input->material),
            'scopes' => $input->scopes === [] ? null : json_encode($input->scopes, JSON_THROW_ON_ERROR),
            'refresh_metadata' => $input->refreshMetadata === [] ? null : $this->encrypter->encrypt($input->refreshMetadata),
            'expires_at' => $input->expiresAt,
            'refreshed_at' => $refreshedAt,
            'updated_at' => $refreshedAt,
        ]);

        return $this->find($reference) ?? throw new InvalidArgumentException('The refreshed connector credential could not be read back.');
    }

    private function validate(CredentialInput $input): void
    {
        if ($input->material === [] || array_any($input->material, fn (string $value, string $key): bool => trim($key) === '' || $value === '')) {
            throw new InvalidArgumentException('Credential material requires named, non-empty values.');
        }

        if (count($input->scopes) !== count(array_unique($input->scopes)) || array_any($input->scopes, fn (string $scope): bool => trim($scope) === '')) {
            throw new InvalidArgumentException('Credential scopes must be unique non-empty values.');
        }

        json_encode($input->refreshMetadata, JSON_THROW_ON_ERROR);
    }

    private function hydrate(stdClass $row): ConnectorCredential
    {
        $material = $this->encrypter->decrypt((string) $row->material);
        $scopes = $row->scopes === null ? [] : json_decode((string) $row->scopes, true, 512, JSON_THROW_ON_ERROR);
        $refreshMetadata = $row->refresh_metadata === null ? [] : $this->encrypter->decrypt((string) $row->refresh_metadata);

        if (! is_array($material) || ! is_array($scopes) || ! is_array($refreshMetadata)) {
            throw new JsonException('Stored connector credentials have an invalid shape.');
        }

        return new ConnectorCredential(
            id: (string) $row->id,
            sourceInstallationId: (string) $row->source_installation_id,
            reference: (string) $row->reference,
            kind: CredentialKind::from((string) $row->kind),
            material: array_map(strval(...), $material),
            scopes: array_values(array_map(strval(...), $scopes)),
            refreshMetadata: $refreshMetadata,
            expiresAt: $row->expires_at === null ? null : new DateTimeImmutable((string) $row->expires_at),
            refreshedAt: $row->refreshed_at === null ? null : new DateTimeImmutable((string) $row->refreshed_at),
            createdAt: new DateTimeImmutable((string) $row->created_at),
        );
    }

    private function table(): Builder
    {
        return $this->connection->table('aleph_connector_credentials');
    }
}
