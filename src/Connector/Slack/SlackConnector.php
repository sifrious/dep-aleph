<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

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

final readonly class SlackConnector implements Backfills, Connector, ConsumesWebhooks, SyncsIncrementally
{
    public function __construct(private ImportSlackActivities $importer, private ConsumeSlackEvent $events) {}

    public function id(): string
    {
        return 'slack';
    }

    public function name(): string
    {
        return 'Slack';
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
            $accepted = $this->events->consume($delivery);

            return OperationResult::completed(count($accepted), ['accepted_references' => $accepted]);
        } catch (Throwable $failure) {
            return OperationResult::failed($failure->getMessage(), ['failure' => $failure::class]);
        }
    }

    private function import(OperationRequest $request, Capability $capability): OperationResult
    {
        try {
            $result = $this->importer->import(new SlackImportRequest($request->sourceReference, $this->string($request, 'stream_id'), $this->string($request, 'run_id'), $this->string($request, 'attempt_id'), $this->partitions($request), $this->versions($request), $capability, $this->integer($request, 'page_size', 200), $this->integer($request, 'max_pages', 100)));

            return $result->complete ? OperationResult::completed(count($result->acceptedReferences), $result->summary()) : OperationResult::partial(count($result->acceptedReferences), json_encode($result->checkpoints, JSON_THROW_ON_ERROR), $result->summary());
        } catch (SlackRateLimited $failure) {
            return OperationResult::failed($failure->getMessage(), ['retryable' => true, 'retry_at' => $failure->retryAt->format(DATE_ATOM)]);
        } catch (Throwable $failure) {
            return OperationResult::failed($failure->getMessage(), ['failure' => $failure::class]);
        }
    }

    private function string(OperationRequest $request, string $key): string
    {
        $value = $request->parameter($key);
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("Slack parameter [{$key}] is required.");
        }

        return $value;
    }

    /** @return list<string> */
    private function partitions(OperationRequest $request): array
    {
        $value = $request->parameter('partitions', ['users', 'channels']);
        if (! is_array($value) || $value === []) {
            throw new InvalidArgumentException('Slack partitions must be a non-empty list.');
        }

        return array_values(array_map(strval(...), $value));
    }

    /** @return array<string, int> */
    private function versions(OperationRequest $request): array
    {
        $value = $request->parameter('expected_checkpoint_versions', []);
        if (! is_array($value)) {
            throw new InvalidArgumentException('Slack checkpoint versions must be an array.');
        }

        return array_map(intval(...), $value);
    }

    private function integer(OperationRequest $request, string $key, int $default): int
    {
        $value = $request->parameter($key, $default);
        if (! is_int($value) || $value < 1 || $value > 1000) {
            throw new InvalidArgumentException("Slack parameter [{$key}] is outside its accepted range.");
        }

        return $value;
    }
}
