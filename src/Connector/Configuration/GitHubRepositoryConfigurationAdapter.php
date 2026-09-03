<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Configuration;

use Sifrious\Aleph\Connector\ConfigurationField;
use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\CredentialKind;

final readonly class GitHubRepositoryConfigurationAdapter implements SourceConfigurationProvider
{
    public function sourceKind(): string
    {
        return 'github';
    }

    public function schema(): ConfigurationSchema
    {
        return new ConfigurationSchema(
            ConfigurationField::text('account', 'GitHub user or organization login.')
                ->fromEnv('ALEPH_GITHUB_ACCOUNT'),
            ConfigurationField::list('repositories', 'Repositories to ingest, written as owner/name.')
                ->fromEnv('ALEPH_GITHUB_REPOSITORIES'),
        );
    }

    public function credentialKind(): CredentialKind
    {
        return CredentialKind::Token;
    }

    public function bound(array $values): array
    {
        $account = is_string($values['account'] ?? null) ? trim($values['account']) : '';

        if (preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?$/', $account) !== 1) {
            throw SourceConfigurationRejected::outOfBounds('GitHub account must be a valid user or organization login.');
        }

        $repositories = [];

        foreach (is_array($values['repositories'] ?? null) ? $values['repositories'] : [] as $repository) {
            if (! is_string($repository) || preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository) !== 1) {
                throw SourceConfigurationRejected::outOfBounds('GitHub repositories must use owner/name coordinates.');
            }

            $repositories[] = strtolower($repository);
        }

        if ($repositories === []) {
            throw SourceConfigurationRejected::outOfBounds('GitHub configuration requires at least one repository.');
        }

        return ['account' => strtolower($account), 'repositories' => array_values(array_unique($repositories))];
    }
}
