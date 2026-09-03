<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector;

use Sifrious\Aleph\Connector\Contracts\Backfills;
use Sifrious\Aleph\Connector\Contracts\ChecksHealth;
use Sifrious\Aleph\Connector\Contracts\ConfiguresSources;
use Sifrious\Aleph\Connector\Contracts\ConsumesWebhooks;
use Sifrious\Aleph\Connector\Contracts\DiscoversSources;
use Sifrious\Aleph\Connector\Contracts\DownloadsArtifacts;
use Sifrious\Aleph\Connector\Contracts\ExtractsContent;
use Sifrious\Aleph\Connector\Contracts\Normalizes;
use Sifrious\Aleph\Connector\Contracts\SyncsIncrementally;
use Sifrious\Aleph\Connector\Contracts\UsesAgents;

enum Capability: string
{
    case ConfiguresSources = 'sources.configure';
    case DiscoversSources = 'sources.discover';
    case Backfills = 'history.backfill';
    case SyncsIncrementally = 'sync.incremental';
    case ConsumesWebhooks = 'webhooks.consume';
    case DownloadsArtifacts = 'artifacts.download';
    case ExtractsContent = 'content.extract';
    case Normalizes = 'records.normalize';
    case ChecksHealth = 'health.check';
    case UsesAgents = 'agents.assist';

    public function contract(): string
    {
        return match ($this) {
            self::ConfiguresSources => ConfiguresSources::class,
            self::DiscoversSources => DiscoversSources::class,
            self::Backfills => Backfills::class,
            self::SyncsIncrementally => SyncsIncrementally::class,
            self::ConsumesWebhooks => ConsumesWebhooks::class,
            self::DownloadsArtifacts => DownloadsArtifacts::class,
            self::ExtractsContent => ExtractsContent::class,
            self::Normalizes => Normalizes::class,
            self::ChecksHealth => ChecksHealth::class,
            self::UsesAgents => UsesAgents::class,
        };
    }

    public function isDispatchable(): bool
    {
        return match ($this) {
            self::ConfiguresSources, self::ExtractsContent, self::Normalizes, self::UsesAgents => false,
            default => true,
        };
    }

    public static function forContract(string $contract): self
    {
        foreach (self::cases() as $capability) {
            if ($capability->contract() === $contract) {
                return $capability;
            }
        }

        throw new \InvalidArgumentException("No capability is mapped to [{$contract}].");
    }

    /**
     * @return list<self>
     */
    public static function dispatchable(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $capability): bool => $capability->isDispatchable(),
        ));
    }
}
