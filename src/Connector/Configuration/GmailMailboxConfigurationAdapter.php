<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Configuration;

use Sifrious\Aleph\Connector\ConfigurationField;
use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\CredentialKind;

final readonly class GmailMailboxConfigurationAdapter implements SourceConfigurationProvider
{
    public function sourceKind(): string
    {
        return 'gmail';
    }

    public function schema(): ConfigurationSchema
    {
        return new ConfigurationSchema(
            ConfigurationField::text('mailbox', 'Gmail address, or me for the authenticated mailbox.')
                ->fromEnv('ALEPH_GMAIL_MAILBOX'),
            ConfigurationField::boolean('include_spam_trash', 'Include messages in spam and trash.')
                ->fromEnv('ALEPH_GMAIL_INCLUDE_SPAM_TRASH')
                ->withDefault(false),
        );
    }

    public function credentialKind(): CredentialKind
    {
        return CredentialKind::OAuth2;
    }

    public function bound(array $values): array
    {
        $mailbox = is_string($values['mailbox'] ?? null) ? strtolower(trim($values['mailbox'])) : '';

        if ($mailbox !== 'me' && filter_var($mailbox, FILTER_VALIDATE_EMAIL) === false) {
            throw SourceConfigurationRejected::outOfBounds('Gmail mailbox must be an email address or me.');
        }

        $includeSpamTrash = $values['include_spam_trash'] ?? false;

        if (! is_bool($includeSpamTrash)) {
            throw SourceConfigurationRejected::outOfBounds('Gmail include_spam_trash must be boolean.');
        }

        return ['mailbox' => $mailbox, 'include_spam_trash' => $includeSpamTrash];
    }
}
