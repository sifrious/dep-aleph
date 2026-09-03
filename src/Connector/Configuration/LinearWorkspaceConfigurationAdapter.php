<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Configuration;

use Sifrious\Aleph\Connector\ConfigurationField;
use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\CredentialKind;

final readonly class LinearWorkspaceConfigurationAdapter implements SourceConfigurationProvider
{
    public function sourceKind(): string
    {
        return 'linear';
    }

    public function schema(): ConfigurationSchema
    {
        return new ConfigurationSchema(
            ConfigurationField::text('workspace', 'Stable Linear workspace identifier.')
                ->fromEnv('ALEPH_LINEAR_WORKSPACE'),
            ConfigurationField::list('streams', 'Linear record types to ingest.')
                ->fromEnv('ALEPH_LINEAR_STREAMS')
                ->withDefault(['projects', 'issues', 'milestones', 'updates']),
        );
    }

    public function credentialKind(): CredentialKind
    {
        return CredentialKind::Token;
    }

    public function bound(array $values): array
    {
        $workspace = is_string($values['workspace'] ?? null) ? trim($values['workspace']) : '';

        if ($workspace === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $workspace) !== 1) {
            throw SourceConfigurationRejected::outOfBounds('Linear workspace must be a stable identifier.');
        }

        $allowed = ['projects', 'issues', 'milestones', 'updates', 'reports', 'tasks', 'links'];
        $streams = [];

        foreach (is_array($values['streams'] ?? null) ? $values['streams'] : [] as $stream) {
            if (! is_string($stream) || ! in_array($stream, $allowed, true)) {
                throw SourceConfigurationRejected::outOfBounds('Linear streams contain an unsupported record type.');
            }

            $streams[] = $stream;
        }

        if ($streams === []) {
            throw SourceConfigurationRejected::outOfBounds('Linear configuration requires at least one stream.');
        }

        return ['workspace' => strtolower($workspace), 'streams' => array_values(array_unique($streams))];
    }
}
