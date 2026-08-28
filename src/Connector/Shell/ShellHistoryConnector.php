<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Shell;

use DateTimeImmutable;
use InvalidArgumentException;
use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\Connector;
use Sifrious\Aleph\Connector\Contracts\Backfills;
use Sifrious\Aleph\Connector\Contracts\SyncsIncrementally;
use Sifrious\Aleph\Connector\Values\OperationRequest;
use Sifrious\Aleph\Connector\Values\OperationResult;
use Throwable;

final readonly class ShellHistoryConnector implements Backfills, Connector, SyncsIncrementally
{
    public function __construct(
        private ShellHistorySources $sources,
        private IngestShellHistory $ingestor,
    ) {}

    public function id(): string
    {
        return 'shell-history';
    }

    public function name(): string
    {
        return 'Shell History';
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
            $scan = $this->sources->get($request->sourceReference)->scan($request->cursor);
            $result = $this->ingestor->ingest(
                $request->sourceReference,
                $this->requiredString($request, 'source_installation_id'),
                $scan->commands,
                new DateTimeImmutable,
                $this->optionalString($request, 'attempt_id'),
            );

            return OperationResult::completed(count($result->acceptedReferences), [
                'commands' => $result->commands,
                'redacted' => $result->redacted,
                'source_revision' => $scan->sourceRevision,
                'cursor' => $scan->cursor,
                'accepted_references' => $result->acceptedReferences,
            ]);
        } catch (Throwable $failure) {
            return OperationResult::failed($failure->getMessage(), ['failure' => $failure::class]);
        }
    }

    private function requiredString(OperationRequest $request, string $key): string
    {
        return $this->optionalString($request, $key)
            ?? throw new InvalidArgumentException("Shell history parameter [{$key}] is required.");
    }

    private function optionalString(OperationRequest $request, string $key): ?string
    {
        $value = $request->parameter($key);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
