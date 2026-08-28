<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Testing\Contracts;

use Sifrious\Aleph\Connector\Capability;
use Sifrious\Aleph\Connector\CapabilitySet;
use Sifrious\Aleph\Connector\ConfigurationField;
use Sifrious\Aleph\Connector\Connector;
use Sifrious\Aleph\Connector\ConnectorManifest;
use Sifrious\Aleph\Connector\Contracts\Backfills;
use Sifrious\Aleph\Connector\Contracts\ChecksHealth;
use Sifrious\Aleph\Connector\Contracts\ConsumesWebhooks;
use Sifrious\Aleph\Connector\Contracts\DiscoversSources;
use Sifrious\Aleph\Connector\Contracts\DownloadsArtifacts;
use Sifrious\Aleph\Connector\Contracts\ExtractsContent;
use Sifrious\Aleph\Connector\Contracts\Normalizes;
use Sifrious\Aleph\Connector\Contracts\SyncsIncrementally;
use Sifrious\Aleph\Connector\Contracts\UsesAgents;
use Sifrious\Aleph\Connector\Values\AgentTaskRequest;
use Sifrious\Aleph\Connector\Values\Artifact;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;
use Sifrious\Aleph\Connector\Values\DiscoveredSources;
use Sifrious\Aleph\Connector\Values\ExtractedContent;
use Sifrious\Aleph\Connector\Values\HealthReport;
use Sifrious\Aleph\Connector\Values\OperationRequest;
use Sifrious\Aleph\Connector\Values\OperationResult;
use Sifrious\Aleph\Connector\Values\WebhookDelivery;
use Throwable;

final class ConnectorContract
{
    /**
     * @return list<string>
     */
    public static function violations(Connector $connector): array
    {
        return [
            ...self::identityViolations($connector),
            ...self::configurationViolations($connector),
            ...self::manifestViolations($connector),
        ];
    }

    /**
     * @return list<string>
     */
    public static function identityViolations(Connector $connector): array
    {
        $violations = [];

        if (trim($connector->id()) === '') {
            $violations[] = 'connector id must not be empty';
        }

        if (preg_match('/^[a-z0-9][a-z0-9._-]*$/', $connector->id()) !== 1) {
            $violations[] = "connector id [{$connector->id()}] must be a lowercase slug";
        }

        if (trim($connector->name()) === '') {
            $violations[] = 'connector name must not be empty';
        }

        if (trim($connector->version()) === '') {
            $violations[] = 'connector version must not be empty';
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    public static function configurationViolations(Connector $connector): array
    {
        $violations = [];

        foreach ($connector->configuration()->toArray() as $field) {
            foreach (['value', 'default', 'secret_value', 'token', 'password'] as $forbidden) {
                if (array_key_exists($forbidden, $field)) {
                    $violations[] = "configuration field [{$field['name']}] exposes [{$forbidden}]";
                }
            }
        }

        foreach ($connector->configuration()->fields as $field) {
            if (self::looksSecret($field) && ! $field->secret) {
                $violations[] = "configuration field [{$field->name}] looks like a credential but is not marked secret";
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    public static function manifestViolations(Connector $connector): array
    {
        $manifest = ConnectorManifest::for($connector);
        $derived = CapabilitySet::of($connector);
        $violations = [];

        foreach ($manifest->capabilities as $capability) {
            $contract = $capability->contract();

            if (! $connector instanceof $contract) {
                $violations[] = "manifest declares [{$capability->value}] but the connector does not implement {$contract}";
            }
        }

        foreach ($derived->all() as $capability) {
            if (! $manifest->supports($capability)) {
                $violations[] = "connector implements [{$capability->value}] but the manifest omits it";
            }
        }

        foreach ($manifest->dispatchableCapabilities() as $capability) {
            if (! $capability->isDispatchable()) {
                $violations[] = "manifest offers [{$capability->value}] as an operation but it is not dispatchable";
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    public static function probe(Connector $connector, Capability $capability): array
    {
        $contract = $capability->contract();

        if (! $connector instanceof $contract) {
            return ["connector does not implement {$contract}"];
        }

        try {
            $result = self::invoke($connector, $capability);
        } catch (Throwable $failure) {
            return ["invoking [{$capability->value}] threw ".$failure::class.': '.$failure->getMessage()];
        }

        if ($capability === Capability::Normalizes) {
            return is_array($result)
                ? []
                : ["[{$capability->value}] returned ".get_debug_type($result).' instead of a list of normalizers'];
        }

        $expected = self::expectedReturn($capability);

        return $result instanceof $expected
            ? []
            : ["[{$capability->value}] returned ".get_debug_type($result)." instead of {$expected}"];
    }

    /**
     * @return list<string>
     */
    public static function probeAll(Connector $connector): array
    {
        $violations = [];

        foreach (CapabilitySet::of($connector)->all() as $capability) {
            foreach (self::probe($connector, $capability) as $violation) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    private static function invoke(Connector $connector, Capability $capability): mixed
    {
        $request = new OperationRequest('contract-probe');

        if ($capability === Capability::DiscoversSources && $connector instanceof DiscoversSources) {
            return $connector->discoverSources($request);
        }

        if ($capability === Capability::Backfills && $connector instanceof Backfills) {
            return $connector->backfill($request);
        }

        if ($capability === Capability::SyncsIncrementally && $connector instanceof SyncsIncrementally) {
            return $connector->syncIncrementally($request);
        }

        if ($capability === Capability::ConsumesWebhooks && $connector instanceof ConsumesWebhooks) {
            return $connector->consumeWebhook(
                new WebhookDelivery('contract-probe', ['X-Event' => 'probe'], '{}', 'signature')
            );
        }

        if ($capability === Capability::DownloadsArtifacts && $connector instanceof DownloadsArtifacts) {
            return $connector->downloadArtifact(new ArtifactRequest('contract-probe', 'artifact-1'));
        }

        if ($capability === Capability::ExtractsContent && $connector instanceof ExtractsContent) {
            return $connector->extractContent(new Artifact('artifact-1', 'text/plain', 'body'));
        }

        if ($capability === Capability::Normalizes && $connector instanceof Normalizes) {
            return $connector->normalizers();
        }

        if ($capability === Capability::ChecksHealth && $connector instanceof ChecksHealth) {
            return $connector->checkHealth();
        }

        if ($capability === Capability::UsesAgents && $connector instanceof UsesAgents) {
            return $connector->runAgentTask(new AgentTaskRequest('contract-probe', 'summarise'));
        }

        throw new \LogicException("Connector does not implement [{$capability->value}].");
    }

    private static function expectedReturn(Capability $capability): string
    {
        return match ($capability) {
            Capability::DiscoversSources => DiscoveredSources::class,
            Capability::DownloadsArtifacts => Artifact::class,
            Capability::ExtractsContent => ExtractedContent::class,
            Capability::ChecksHealth => HealthReport::class,
            default => OperationResult::class,
        };
    }

    private static function looksSecret(ConfigurationField $field): bool
    {
        return preg_match('/(token|secret|password|api_key|apikey|credential|private_key)/i', $field->name) === 1;
    }
}
