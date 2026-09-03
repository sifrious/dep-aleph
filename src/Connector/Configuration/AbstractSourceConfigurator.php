<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Configuration;

use Sifrious\Aleph\Connector\Connector;
use Sifrious\Aleph\Connector\Contracts\ConfiguresSources;

/**
 * The shared invariants of `sources.configure`, in one place: submitted values are checked
 * against the provider's schema, unknown inputs and inline credential values are refused,
 * absent inputs fall back to their environment value or declared default, the credential
 * requirement is enforced as a reference, and the accepted declaration is recorded as an
 * observation under a stable source reference.
 *
 * A subclass supplies only the provider for its source kind.
 */
abstract class AbstractSourceConfigurator implements ConfiguresSources
{
    /** @var null|callable(string): (string|null) */
    private $environment;

    /**
     * @param  null|callable(string): (string|null)  $environment  reads an environment variable; defaults to the process environment
     */
    public function __construct(
        private readonly Connector $connector,
        private readonly ?SourceConfigurationRecorder $recorder = null,
        ?callable $environment = null,
    ) {
        $this->environment = $environment;
    }

    abstract protected function provider(): SourceConfigurationProvider;

    final public function configureSource(SourceConfigurationRequest $request): SourceConfiguration
    {
        $provider = $this->provider();
        $schema = $provider->schema();
        $kind = $provider->sourceKind();

        if (preg_match('/^[a-z0-9][a-z0-9._-]*$/', $request->sourceKey) !== 1) {
            throw SourceConfigurationRejected::invalidSourceKey($request->sourceKey);
        }

        foreach ($request->values as $name => $value) {
            $field = $schema->field((string) $name);

            if ($field === null) {
                throw SourceConfigurationRejected::unknownInput((string) $name, $kind);
            }

            if ($field->secret) {
                throw SourceConfigurationRejected::inlineSecret($field->name);
            }
        }

        $values = [...$schema->resolveDefaults($this->environment), ...$request->values];

        foreach ($schema->required() as $name) {
            if (! array_key_exists($name, $values) || $values[$name] === null || $values[$name] === []) {
                throw SourceConfigurationRejected::missingInput($name);
            }
        }

        $credentialKind = $provider->credentialKind();
        $reference = $request->credentialReference === null ? null : trim($request->credentialReference);

        if ($credentialKind !== null && ($reference === null || $reference === '')) {
            throw SourceConfigurationRejected::missingCredential($kind, $credentialKind);
        }

        if ($credentialKind === null && $reference !== null && $reference !== '') {
            throw SourceConfigurationRejected::unexpectedCredential($kind);
        }

        $configuration = new SourceConfiguration(
            sourceReference: $kind.':'.$request->sourceKey,
            connectorId: $this->connector->id(),
            connectorVersion: $this->connector->version(),
            sourceKey: $request->sourceKey,
            name: $request->name === '' ? $request->sourceKey : $request->name,
            values: $provider->bound($values),
            credentialReference: $reference === '' ? null : $reference,
            credentialKind: $credentialKind,
            owner: $request->owner,
            configuredAt: $request->submittedAt(),
        );

        $this->recorder?->record($configuration);

        return $configuration;
    }
}
