<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector;

use InvalidArgumentException;
use Sifrious\Aleph\Connector\Contracts\Backfills;
use Sifrious\Aleph\Connector\Contracts\ChecksHealth;
use Sifrious\Aleph\Connector\Contracts\ConsumesWebhooks;
use Sifrious\Aleph\Connector\Contracts\DiscoversSources;
use Sifrious\Aleph\Connector\Contracts\DownloadsArtifacts;
use Sifrious\Aleph\Connector\Contracts\SyncsIncrementally;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;
use Sifrious\Aleph\Connector\Values\OperationRequest;
use Sifrious\Aleph\Connector\Values\WebhookDelivery;

final readonly class ConnectorDispatcher
{
    public function __construct(private ConnectorRegistry $registry) {}

    public function supports(string $connectorId, Capability $capability): bool
    {
        return $this->rejectionFor($connectorId, $capability) === null;
    }

    public function rejectionFor(string $connectorId, Capability $capability): ?Rejection
    {
        if (! $this->registry->has($connectorId)) {
            return new Rejection(
                Rejection::UNKNOWN_CONNECTOR,
                $connectorId,
                $capability->value,
                [],
                "No connector is registered with id [{$connectorId}].",
            );
        }

        $manifest = $this->registry->manifest($connectorId);

        if (! $manifest->supports($capability)) {
            return new Rejection(
                Rejection::CAPABILITY_NOT_SUPPORTED,
                $connectorId,
                $capability->value,
                $manifest->capabilityIds(),
                "Connector [{$connectorId}] does not support [{$capability->value}].",
            );
        }

        if (! $capability->isDispatchable()) {
            return new Rejection(
                Rejection::CAPABILITY_NOT_DISPATCHABLE,
                $connectorId,
                $capability->value,
                $manifest->availableOperations(),
                "Capability [{$capability->value}] participates in a run and cannot be dispatched on its own.",
            );
        }

        return null;
    }

    public function dispatch(string $connectorId, Capability $capability, ?object $request = null): mixed
    {
        $rejection = $this->rejectionFor($connectorId, $capability);

        if ($rejection !== null) {
            throw UnsupportedCapability::from($rejection);
        }

        $connector = $this->registry->get($connectorId);

        return match ($capability) {
            Capability::DiscoversSources => $this->discover($connector, $request),
            Capability::Backfills => $this->backfill($connector, $request),
            Capability::SyncsIncrementally => $this->sync($connector, $request),
            Capability::ConsumesWebhooks => $this->webhook($connector, $request),
            Capability::DownloadsArtifacts => $this->download($connector, $request),
            Capability::ChecksHealth => $this->health($connector),
            default => throw UnsupportedCapability::from(new Rejection(
                Rejection::CAPABILITY_NOT_DISPATCHABLE,
                $connectorId,
                $capability->value,
                [],
                "No dispatch route exists for [{$capability->value}].",
            )),
        };
    }

    private function discover(Connector $connector, ?object $request): mixed
    {
        assert($connector instanceof DiscoversSources);

        return $connector->discoverSources($this->expect($request, OperationRequest::class));
    }

    private function backfill(Connector $connector, ?object $request): mixed
    {
        assert($connector instanceof Backfills);

        return $connector->backfill($this->expect($request, OperationRequest::class));
    }

    private function sync(Connector $connector, ?object $request): mixed
    {
        assert($connector instanceof SyncsIncrementally);

        return $connector->syncIncrementally($this->expect($request, OperationRequest::class));
    }

    private function webhook(Connector $connector, ?object $request): mixed
    {
        assert($connector instanceof ConsumesWebhooks);

        return $connector->consumeWebhook($this->expect($request, WebhookDelivery::class));
    }

    private function download(Connector $connector, ?object $request): mixed
    {
        assert($connector instanceof DownloadsArtifacts);

        return $connector->downloadArtifact($this->expect($request, ArtifactRequest::class));
    }

    private function health(Connector $connector): mixed
    {
        assert($connector instanceof ChecksHealth);

        return $connector->checkHealth();
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $expected
     * @return T
     */
    private function expect(?object $request, string $expected): object
    {
        if (! $request instanceof $expected) {
            $given = $request === null ? 'null' : $request::class;

            throw new InvalidArgumentException("Expected a {$expected} request, received [{$given}].");
        }

        return $request;
    }
}
