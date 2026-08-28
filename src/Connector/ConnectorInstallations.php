<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector;

use DateTimeImmutable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use stdClass;

final readonly class ConnectorInstallations
{
    public function __construct(
        private ConnectionInterface $connection,
        private Encrypter $encrypter,
    ) {}

    /**
     * @param  array<string, mixed>  $configuration
     */
    public function create(
        Connector $connector,
        string $label,
        array $configuration = [],
        ?string $credentialsReference = null,
        ?string $owner = null,
        ?string $externalAccountId = null,
        ?string $funesSourceAccountId = null,
    ): ConnectorInstallation {
        $installation = new ConnectorInstallation(
            id: (string) Str::ulid(),
            connectorId: $connector->id(),
            connectorVersion: $connector->version(),
            label: $label,
            externalAccountId: $externalAccountId,
            funesSourceAccountId: $funesSourceAccountId,
            enabled: true,
            configuration: $configuration,
            credentialsReference: $credentialsReference,
            owner: $owner,
            createdAt: new DateTimeImmutable,
        );

        $now = Carbon::now();

        $this->table()->insert([
            'id' => $installation->id,
            'connector_id' => $installation->connectorId,
            'connector_version' => $installation->connectorVersion,
            'label' => $installation->label,
            'external_account_id' => $externalAccountId,
            'funes_source_account_id' => $funesSourceAccountId,
            'enabled' => true,
            'configuration' => $this->encrypter->encrypt($configuration),
            'credentials_reference' => $credentialsReference,
            'owner' => $owner,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $installation;
    }

    public function find(string $id): ?ConnectorInstallation
    {
        $row = $this->table()->where('id', $id)->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    /**
     * @return list<ConnectorInstallation>
     */
    public function forConnector(string $connectorId): array
    {
        return $this->hydrateAll($this->table()->where('connector_id', $connectorId)->orderBy('label'));
    }

    /**
     * @return list<ConnectorInstallation>
     */
    public function enabled(): array
    {
        return $this->hydrateAll($this->table()->where('enabled', true)->orderBy('connector_id'));
    }

    public function enable(string $id): void
    {
        $this->setEnabled($id, true);
    }

    public function disable(string $id): void
    {
        $this->setEnabled($id, false);
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    public function reconfigure(string $id, array $configuration): ?ConnectorInstallation
    {
        $this->table()->where('id', $id)->update([
            'configuration' => $this->encrypter->encrypt($configuration),
            'updated_at' => Carbon::now(),
        ]);

        return $this->find($id);
    }

    public function delete(string $id): void
    {
        $this->table()->where('id', $id)->delete();
    }

    public function bindCredentialsReference(string $id, string $reference): void
    {
        if (trim($reference) === '') {
            throw new \InvalidArgumentException('A credentials reference cannot be empty.');
        }

        $this->table()->where('id', $id)->update([
            'credentials_reference' => $reference,
            'updated_at' => Carbon::now(),
        ]);
    }

    private function setEnabled(string $id, bool $enabled): void
    {
        $this->table()->where('id', $id)->update([
            'enabled' => $enabled,
            'updated_at' => Carbon::now(),
        ]);
    }

    /**
     * @return list<ConnectorInstallation>
     */
    private function hydrateAll(Builder $query): array
    {
        return array_values(array_map(
            fn (stdClass $row): ConnectorInstallation => $this->hydrate($row),
            $query->get()->all(),
        ));
    }

    private function hydrate(stdClass $row): ConnectorInstallation
    {
        $configuration = $this->encrypter->decrypt($row->configuration);

        return new ConnectorInstallation(
            id: $row->id,
            connectorId: $row->connector_id,
            connectorVersion: $row->connector_version,
            label: $row->label,
            externalAccountId: $row->external_account_id,
            funesSourceAccountId: $row->funes_source_account_id,
            enabled: (bool) $row->enabled,
            configuration: is_array($configuration) ? $configuration : [],
            credentialsReference: $row->credentials_reference,
            owner: $row->owner,
            createdAt: new DateTimeImmutable((string) $row->created_at),
        );
    }

    private function table(): Builder
    {
        return $this->connection->table('aleph_connector_installations');
    }
}
