<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Configuration;

use Sifrious\Aleph\Connector\Capability;
use Sifrious\Aleph\Connector\CapabilitySet;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\Contracts\ConfiguresSources;
use Sifrious\Aleph\Connector\Rejection;
use Sifrious\Aleph\Connector\UnsupportedCapability;

/**
 * Resolves the connector, lets its configurator apply the shared validation, persists the
 * accepted configuration as an installation, and returns the source reference.
 */
final readonly class ConfigureSource
{
    public function __construct(
        private ConnectorRegistry $registry,
        private ConnectorInstallations $installations,
    ) {}

    public function configure(string $connectorId, SourceConfigurationRequest $request): ConfiguredSource
    {
        $connector = $this->registry->get($connectorId);

        if (! $connector instanceof ConfiguresSources) {
            throw UnsupportedCapability::from(new Rejection(
                Rejection::CAPABILITY_NOT_SUPPORTED,
                $connectorId,
                Capability::ConfiguresSources->value,
                CapabilitySet::of($connector)->ids(),
                "Connector [{$connectorId}] does not configure sources.",
            ));
        }

        $configuration = $connector->configureSource($request);

        $installation = $this->installations->create(
            connector: $connector,
            label: $configuration->name,
            configuration: $configuration->values,
            credentialsReference: $configuration->credentialReference,
            owner: $configuration->owner,
            externalAccountId: $configuration->sourceReference,
        );

        return new ConfiguredSource($configuration, $installation);
    }
}
