<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Linear;

use InvalidArgumentException;
use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\Connector;
use Sifrious\Aleph\Connector\Contracts\Backfills;
use Sifrious\Aleph\Connector\Contracts\ConsumesWebhooks;
use Sifrious\Aleph\Connector\Contracts\SyncsIncrementally;
use Sifrious\Aleph\Connector\Values\OperationRequest;
use Sifrious\Aleph\Connector\Values\OperationResult;
use Sifrious\Aleph\Connector\Values\WebhookDelivery;
use Sifrious\Aleph\Ingestion\Capability;
use Throwable;

final readonly class LinearActivityConnector implements Backfills, Connector, ConsumesWebhooks, SyncsIncrementally
{
    public function __construct(
        private ImportLinearActivities $importer,
        private ConsumeLinearWebhook $webhooks,
    ) {}

    public function id(): string
    {
        return 'linear-activity';
    }

    public function name(): string
    {
        return 'Linear Activity';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function configuration(): ConfigurationSchema
    {
        return new ConfigurationSchema;
    }

    public function backfill(OperationRequest $request): OperationResult
    {
        return $this->import($request, Capability::Backfill);
    }

    public function syncIncrementally(OperationRequest $request): OperationResult
    {
        return $this->import($request, Capability::IncrementalSync);
    }

    public function consumeWebhook(WebhookDelivery $delivery): OperationResult
    {
        try {
            $accepted = $this->webhooks->consume($delivery);

            return OperationResult::completed(count($accepted), ['accepted_references' => $accepted]);
        } catch (Throwable $failure) {
            return OperationResult::failed($failure->getMessage(), ['failure' => $failure::class]);
        }
    }

    private function import(OperationRequest $request, Capability $capability): OperationResult
    {
        try {
            $result = $this->importer->import(new LinearImportRequest(
                sourceReference: $request->sourceReference,
                streamId: $this->requiredString($request, 'stream_id'),
                runId: $this->requiredString($request, 'run_id'),
                attemptId: $this->requiredString($request, 'attempt_id'),
                streams: $this->streams($request),
                expectedCheckpointVersions: $this->expectedVersions($request),
                capability: $capability,
                pageSize: $this->pageSize($request),
            ));

            return OperationResult::completed(count($result->acceptedReferences), $result->summary());
        } catch (Throwable $failure) {
            return OperationResult::failed($failure->getMessage(), ['failure' => $failure::class]);
        }
    }

    private function requiredString(OperationRequest $request, string $key): string
    {
        $value = $request->parameter($key);

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Linear activity parameter [{$key}] is required.");
        }

        return $value;
    }

    /** @return list<LinearStream> */
    private function streams(OperationRequest $request): array
    {
        $values = $request->parameter('streams', array_map(static fn (LinearStream $stream): string => $stream->value, LinearStream::cases()));

        if (! is_array($values) || $values === []) {
            throw new InvalidArgumentException('Linear activity streams must be a non-empty list.');
        }

        return array_map(static fn (mixed $value): LinearStream => LinearStream::from((string) $value), array_values($values));
    }

    /** @return array<string, int> */
    private function expectedVersions(OperationRequest $request): array
    {
        $values = $request->parameter('expected_checkpoint_versions', []);

        if (! is_array($values)) {
            throw new InvalidArgumentException('Linear checkpoint versions must be keyed integers.');
        }

        $versions = [];

        foreach ($values as $stream => $version) {
            if (! is_string($stream) || ! is_int($version) || $version < 0) {
                throw new InvalidArgumentException('Linear checkpoint versions must be keyed non-negative integers.');
            }

            $versions[$stream] = $version;
        }

        return $versions;
    }

    private function pageSize(OperationRequest $request): int
    {
        $value = $request->parameter('page_size', 100);

        if (! is_int($value) || $value < 1 || $value > 100) {
            throw new InvalidArgumentException('Linear activity page size must be between 1 and 100.');
        }

        return $value;
    }
}
