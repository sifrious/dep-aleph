<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

final readonly class RegisterSourceAccount
{
    public function __construct(
        private ConnectionInterface $connection,
        private ConnectorInstallations $installations,
        private ConnectorCredentials $credentials,
    ) {}

    public function register(Connector $connector, SourceAccountRegistration $registration): RegisteredSourceAccount
    {
        if (trim($registration->label) === '' || trim($registration->externalAccountId) === '' || trim($registration->funesSourceAccountId) === '') {
            throw new InvalidArgumentException('A source account requires label, external account identity, and Funes source account identity.');
        }

        if ($registration->credential !== null && $registration->credentialsReference !== null) {
            throw new InvalidArgumentException('A source account accepts managed credentials or an external credential reference, not both.');
        }

        return $this->connection->transaction(function () use ($connector, $registration): RegisteredSourceAccount {
            $installation = $this->installations->create(
                connector: $connector,
                label: $registration->label,
                configuration: $registration->settings,
                credentialsReference: $registration->credentialsReference,
                owner: $registration->owner,
                externalAccountId: $registration->externalAccountId,
                funesSourceAccountId: $registration->funesSourceAccountId,
            );
            $credential = $registration->credential === null
                ? null
                : $this->credentials->create($installation->id, $registration->credential);

            return new RegisteredSourceAccount(
                $this->installations->find($installation->id) ?? $installation,
                $credential,
            );
        });
    }
}
