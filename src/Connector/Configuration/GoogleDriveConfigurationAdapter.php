<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Configuration;

use Sifrious\Aleph\Connector\ConfigurationField;
use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\CredentialKind;

final readonly class GoogleDriveConfigurationAdapter implements SourceConfigurationProvider
{
    public function sourceKind(): string
    {
        return 'google-drive';
    }

    public function schema(): ConfigurationSchema
    {
        return new ConfigurationSchema(
            ConfigurationField::text('drive', 'Stable Google Drive account or shared-drive identifier.')
                ->fromEnv('ALEPH_GOOGLE_DRIVE'),
        );
    }

    public function credentialKind(): CredentialKind
    {
        return CredentialKind::OAuth2;
    }

    public function bound(array $values): array
    {
        $drive = is_string($values['drive'] ?? null) ? trim($values['drive']) : '';

        if ($drive === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@-]*$/', $drive) !== 1) {
            throw SourceConfigurationRejected::outOfBounds('Google Drive requires a stable account or shared-drive identifier.');
        }

        return ['drive' => $drive];
    }
}
