<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Console;

use Sifrious\Aleph\Connector\ConnectorInstallations;

final class DisableSourceCommand extends AlephCommand
{
    protected $signature = 'aleph:source:disable {installation : Source installation ID}';

    protected $description = 'Disable an Aleph source installation.';

    public function handle(ConnectorInstallations $installations): int
    {
        $id = (string) $this->argument('installation');

        if ($installations->find($id) === null) {
            $this->components->error("Source installation [{$id}] does not exist.");

            return self::FAILURE;
        }

        $installations->disable($id);
        $this->components->info("Disabled source installation [{$id}].");

        return self::SUCCESS;
    }
}
