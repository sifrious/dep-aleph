<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Git;

use InvalidArgumentException;
use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\Connector;
use Sifrious\Aleph\Connector\Contracts\Backfills;
use Sifrious\Aleph\Connector\Contracts\SyncsIncrementally;
use Sifrious\Aleph\Connector\Values\OperationRequest;
use Sifrious\Aleph\Connector\Values\OperationResult;
use Throwable;

final readonly class GitHistoryConnector implements Backfills, Connector, SyncsIncrementally
{
    public function __construct(private ImportGitHistory $importer) {}

    public function id(): string
    {
        return 'git-history';
    }

    public function name(): string
    {
        return 'Git History';
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
        return $this->execute($request);
    }

    public function syncIncrementally(OperationRequest $request): OperationResult
    {
        return $this->execute($request);
    }

    private function execute(OperationRequest $request): OperationResult
    {
        try {
            $result = $this->importer->import(new GitImportRequest(
                sourceReference: $request->sourceReference,
                ref: $this->requiredString($request, 'ref'),
                streamId: $this->requiredString($request, 'stream_id'),
                runId: $this->requiredString($request, 'run_id'),
                attemptId: $this->requiredString($request, 'attempt_id'),
                expectedCheckpointVersion: $this->expectedVersion($request),
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
            throw new InvalidArgumentException("Git import parameter [{$key}] is required.");
        }

        return $value;
    }

    private function expectedVersion(OperationRequest $request): int
    {
        $value = $request->parameter('expected_checkpoint_version', 0);

        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException('Git import expected checkpoint version must be a non-negative integer.');
        }

        return $value;
    }
}
