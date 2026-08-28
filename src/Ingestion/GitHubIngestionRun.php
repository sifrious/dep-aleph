<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use InvalidArgumentException;

final readonly class GitHubIngestionRun
{
    /**
     * @param  list<string>  $repositoryReferences
     * @param  list<string>  $providerReconciliationIds
     * @param  list<array<string, mixed>>  $targets
     */
    public function __construct(
        public IngestionRunReadModel $ingestion,
        public GitHubRunScope $scope,
        public string $accountReference,
        public array $repositoryReferences,
        public ?string $repoWatchReference,
        public array $providerReconciliationIds,
        public array $targets,
    ) {}

    public static function from(IngestionRunReadModel $ingestion): self
    {
        if ($ingestion->run->connectorId !== 'github') {
            throw new InvalidArgumentException('A GitHub ingestion projection requires the github connector.');
        }

        $extensions = $ingestion->run->parameters['extensions'] ?? [];
        $github = is_array($extensions) && is_array($extensions['github'] ?? null) ? $extensions['github'] : [];
        $targets = $ingestion->run->parameters['targets'] ?? [];

        return new self(
            ingestion: $ingestion,
            scope: GitHubRunScope::from((string) ($github['scope'] ?? 'all')),
            accountReference: (string) ($github['account'] ?? $ingestion->run->sourceReference),
            repositoryReferences: self::strings($github['repositories'] ?? []),
            repoWatchReference: is_string($github['repo_watch'] ?? null) ? $github['repo_watch'] : null,
            providerReconciliationIds: self::strings($github['provider_reconciliation_ids'] ?? []),
            targets: self::targets($targets),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run' => $this->ingestion->toArray(),
            'github' => [
                'scope' => $this->scope->value,
                'account' => $this->accountReference,
                'repositories' => $this->repositoryReferences,
                'repo_watch' => $this->repoWatchReference,
                'provider_reconciliation_ids' => $this->providerReconciliationIds,
                'targets' => $this->targets,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_map(strval(...), $values)) : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function targets(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, is_array(...)));
    }
}
