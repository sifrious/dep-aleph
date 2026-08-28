<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Communication;

use InvalidArgumentException;
use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\Connector;
use Sifrious\Aleph\Connector\Contracts\Backfills;
use Sifrious\Aleph\Connector\Contracts\SyncsIncrementally;
use Sifrious\Aleph\Connector\Values\OperationRequest;
use Sifrious\Aleph\Connector\Values\OperationResult;
use Sifrious\Aleph\Ingestion\Capability;
use Throwable;

final readonly class ProviderCommunicationConnector implements Backfills, Connector, SyncsIncrementally
{
    public function __construct(
        private CommunicationProvider $provider,
        private ImportCommunicationRecords $importer,
    ) {}

    public function id(): string
    {
        return $this->provider->value;
    }

    public function name(): string
    {
        return match ($this->provider) {
            CommunicationProvider::Telegram => 'Telegram',
            CommunicationProvider::Sms => 'SMS and MMS',
            CommunicationProvider::Discord => 'Discord',
        };
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

    private function import(OperationRequest $request, Capability $capability): OperationResult
    {
        try {
            $result = $this->importer->import(new CommunicationImportRequest(
                $this->provider,
                $request->sourceReference,
                $this->requiredString($request, 'stream_id'),
                $this->requiredString($request, 'run_id'),
                $this->requiredString($request, 'attempt_id'),
                $this->boundedInteger($request, 'expected_checkpoint_version', 0, 0),
                $capability,
                $this->boundedInteger($request, 'page_size', 100, 1, 100),
                $this->boundedInteger($request, 'max_pages', 100, 1, 1000),
            ));

            return $result->complete
                ? OperationResult::completed(count($result->acceptedReferences), $result->summary())
                : OperationResult::partial(count($result->acceptedReferences), $result->checkpoint ?? '', $result->summary());
        } catch (Throwable $failure) {
            return OperationResult::failed($failure->getMessage(), ['failure' => $failure::class]);
        }
    }

    private function requiredString(OperationRequest $request, string $key): string
    {
        $value = $request->parameter($key);

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("{$this->name()} parameter [{$key}] is required.");
        }

        return $value;
    }

    private function boundedInteger(OperationRequest $request, string $key, int $default, int $minimum, int $maximum = PHP_INT_MAX): int
    {
        $value = $request->parameter($key, $default);

        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException("{$this->name()} parameter [{$key}] is outside its accepted range.");
        }

        return $value;
    }
}
