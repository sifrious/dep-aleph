<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Console;

use Sifrious\Aleph\Connector\ConnectorInstallations;

final class EnableSourceCommand extends AlephCommand
{
    protected $signature = 'aleph:source:enable {installation : Source installation ID}';

    protected $description = 'Enable an Aleph source installation.';

    public function handle(ConnectorInstallations $installations): int
    {
        $id = (string) $this->argument('installation');

        if ($installations->find($id) === null) {
            $this->components->error("Source installation [{$id}] does not exist.");

            return self::FAILURE;
        }

        $installations->enable($id);
        $this->components->info("Enabled source installation [{$id}].");

        return self::SUCCESS;
    }
}
