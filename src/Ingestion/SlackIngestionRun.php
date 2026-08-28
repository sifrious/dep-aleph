<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use InvalidArgumentException;

final readonly class SlackIngestionRun
{
    /**
     * @param  list<string>  $channelReferences
     * @param  list<string>  $providerReconciliationIds
     * @param  list<array<string, mixed>>  $targets
     */
    public function __construct(
        public IngestionRunReadModel $ingestion,
        public SlackRunScope $scope,
        public string $workspaceReference,
        public array $channelReferences,
        public ?string $providerRunId,
        public array $providerReconciliationIds,
        public array $targets,
    ) {}

    public static function from(IngestionRunReadModel $ingestion): self
    {
        if ($ingestion->run->connectorId !== 'slack') {
            throw new InvalidArgumentException('A Slack ingestion projection requires the slack connector.');
        }

        $extensions = $ingestion->run->parameters['extensions'] ?? [];
        $slack = is_array($extensions) && is_array($extensions['slack'] ?? null) ? $extensions['slack'] : [];
        $targets = $ingestion->run->parameters['targets'] ?? [];

        return new self(
            $ingestion,
            SlackRunScope::from((string) ($slack['scope'] ?? 'channels')),
            (string) ($slack['workspace'] ?? $ingestion->run->sourceReference),
            self::strings($slack['channels'] ?? []),
            is_string($slack['provider_run_id'] ?? null) ? $slack['provider_run_id'] : null,
            self::strings($slack['provider_reconciliation_ids'] ?? []),
            self::targets($targets),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'run' => $this->ingestion->toArray(),
            'slack' => [
                'scope' => $this->scope->value,
                'workspace' => $this->workspaceReference,
                'channels' => $this->channelReferences,
                'provider_run_id' => $this->providerRunId,
                'provider_reconciliation_ids' => $this->providerReconciliationIds,
                'targets' => $this->targets,
            ],
        ];
    }

    /** @return list<string> */
    private static function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_map(strval(...), $values)) : [];
    }

    /** @return list<array<string, mixed>> */
    private static function targets(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, is_array(...))) : [];
    }
}
