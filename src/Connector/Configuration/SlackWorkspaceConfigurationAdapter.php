<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Configuration;

use Sifrious\Aleph\Connector\ConfigurationField;
use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\CredentialKind;

/**
 * Translates a Slack workspace handle and its channel bounds into the neutral configuration
 * record. The workspace token stays wherever the host keeps credentials; only its reference
 * reaches Aleph.
 */
final readonly class SlackWorkspaceConfigurationAdapter implements SourceConfigurationProvider
{
    public function sourceKind(): string
    {
        return 'slack';
    }

    public function schema(): ConfigurationSchema
    {
        return new ConfigurationSchema(
            ConfigurationField::text('workspace', 'Slack workspace identifier, such as T0123456789.')
                ->fromEnv('ALEPH_SLACK_WORKSPACE'),
            ConfigurationField::list('channels', 'Channel identifiers to ingest; empty means every channel the credential can read.')
                ->fromEnv('ALEPH_SLACK_CHANNELS')
                ->withDefault([]),
            ConfigurationField::number('history_days', 'How far back a first backfill reaches, in days.', required: false)
                ->fromEnv('ALEPH_SLACK_HISTORY_DAYS')
                ->withDefault(30),
        );
    }

    public function credentialKind(): CredentialKind
    {
        return CredentialKind::Token;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function bound(array $values): array
    {
        $workspace = is_string($values['workspace'] ?? null) ? trim($values['workspace']) : '';

        if (preg_match('/^T[A-Z0-9]{1,}$/', $workspace) !== 1) {
            throw SourceConfigurationRejected::outOfBounds(
                "Slack workspace [{$workspace}] must be a workspace identifier such as T0123456789."
            );
        }

        $channels = [];

        foreach (is_array($values['channels'] ?? null) ? $values['channels'] : [] as $channel) {
            if (! is_string($channel) || preg_match('/^[CGD][A-Z0-9]{1,}$/', $channel) !== 1) {
                throw SourceConfigurationRejected::outOfBounds('Slack channels must be channel identifiers such as C0123456789.');
            }

            $channels[] = $channel;
        }

        $days = is_int($values['history_days'] ?? null) ? $values['history_days'] : 30;

        if ($days < 1) {
            throw SourceConfigurationRejected::outOfBounds('Slack history must reach back at least one day.');
        }

        return [
            'workspace' => $workspace,
            'channels' => $channels,
            'history_days' => $days,
        ];
    }
}
