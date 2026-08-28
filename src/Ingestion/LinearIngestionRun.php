<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use InvalidArgumentException;

final readonly class LinearIngestionRun
{
    /**
     * @param  list<string>  $projectReferences
     * @param  list<string>  $providerReconciliationIds
     * @param  list<array<string, mixed>>  $targets
     */
    public function __construct(
        public IngestionRunReadModel $ingestion,
        public LinearRunScope $scope,
        public string $workspaceReference,
        public array $projectReferences,
        public ?string $providerRunId,
        public array $providerReconciliationIds,
        public array $targets,
    ) {}

    public static function from(IngestionRunReadModel $ingestion): self
    {
        if ($ingestion->run->connectorId !== 'linear') {
            throw new InvalidArgumentException('A Linear ingestion projection requires the linear connector.');
        }

        $extensions = $ingestion->run->parameters['extensions'] ?? [];
        $linear = is_array($extensions) && is_array($extensions['linear'] ?? null) ? $extensions['linear'] : [];
        $targets = $ingestion->run->parameters['targets'] ?? [];

        return new self(
            ingestion: $ingestion,
            scope: LinearRunScope::from((string) ($linear['scope'] ?? 'all')),
            workspaceReference: (string) ($linear['workspace'] ?? $ingestion->run->sourceReference),
            projectReferences: self::strings($linear['projects'] ?? []),
            providerRunId: is_string($linear['provider_run_id'] ?? null) ? $linear['provider_run_id'] : null,
            providerReconciliationIds: self::strings($linear['provider_reconciliation_ids'] ?? []),
            targets: self::targets($targets),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'run' => $this->ingestion->toArray(),
            'linear' => [
                'scope' => $this->scope->value,
                'workspace' => $this->workspaceReference,
                'projects' => $this->projectReferences,
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
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, is_array(...)));
    }
}
