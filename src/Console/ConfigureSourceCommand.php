<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Console;

use Sifrious\Aleph\Connector\Configuration\ConfigureSource;
use Sifrious\Aleph\Connector\Configuration\SourceConfigurationRequest;
use Throwable;

final class ConfigureSourceCommand extends AlephCommand
{
    protected $signature = 'aleph:source:configure
        {connector : Connector ID}
        {source-key : Stable source key}
        {name : Display name}
        {--value=* : Provider bound in key=value form}
        {--credential-reference= : Opaque host credential reference}
        {--owner= : Stable owner reference}
        {--json : Emit JSON}';

    protected $description = 'Validate and record a connector source configuration.';

    public function handle(ConfigureSource $configure): int
    {
        try {
            $configured = $configure->configure(
                (string) $this->argument('connector'),
                new SourceConfigurationRequest(
                    sourceKey: (string) $this->argument('source-key'),
                    name: (string) $this->argument('name'),
                    values: CommandInput::pairs((array) $this->option('value')),
                    credentialReference: $this->stringOption('credential-reference'),
                    owner: $this->stringOption('owner'),
                ),
            );
        } catch (Throwable $failure) {
            return $this->failure($failure);
        }

        $data = $configured->toArray();

        if ((bool) $this->option('json')) {
            return $this->json($data);
        }

        $this->table(['Field', 'Value'], [
            ['Source', $configured->sourceReference()],
            ['Installation', $configured->installation->id],
            ['Connector', $configured->installation->connectorId],
            ['Enabled', $this->display($configured->installation->enabled)],
        ]);

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
