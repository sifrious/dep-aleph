<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GitHub;

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

final readonly class GitHubActivityConnector implements Backfills, Connector, ConsumesWebhooks, SyncsIncrementally
{
    public function __construct(
        private ImportGitHubActivities $importer,
        private ConsumeGitHubWebhook $webhooks,
    ) {}

    public function id(): string
    {
        return 'github-activity';
    }

    public function name(): string
    {
        return 'GitHub Activity';
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
            $result = $this->importer->import(new GitHubImportRequest(
                sourceReference: $request->sourceReference,
                repository: $this->requiredString($request, 'repository'),
                streamId: $this->requiredString($request, 'stream_id'),
                runId: $this->requiredString($request, 'run_id'),
                attemptId: $this->requiredString($request, 'attempt_id'),
                expectedCheckpointVersion: $this->expectedVersion($request),
                capability: $capability,
                pageSize: $this->pageSize($request),
            ));

            return OperationResult::completed(count($result->acceptedReferences), $result->summary());
        } catch (Throwable $failure) {
            return OperationResult::failed($failure->getMessage(), array_filter([
                'failure' => $failure::class,
                'retry_at' => $failure instanceof GitHubRateLimited ? $failure->retryAt->format(DATE_ATOM) : null,
            ]));
        }
    }

    private function requiredString(OperationRequest $request, string $key): string
    {
        $value = $request->parameter($key);

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("GitHub activity parameter [{$key}] is required.");
        }

        return $value;
    }

    private function expectedVersion(OperationRequest $request): int
    {
        $value = $request->parameter('expected_checkpoint_version', 0);

        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException('GitHub activity expected checkpoint version must be non-negative.');
        }

        return $value;
    }

    private function pageSize(OperationRequest $request): int
    {
        $value = $request->parameter('page_size', 100);

        if (! is_int($value) || $value < 1 || $value > 100) {
            throw new InvalidArgumentException('GitHub activity page size must be between 1 and 100.');
        }

        return $value;
    }
}
