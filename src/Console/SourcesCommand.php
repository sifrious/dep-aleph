<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Console;

use Sifrious\Aleph\Connector\SourceInstallationQueries;

final class SourcesCommand extends AlephCommand
{
    protected $signature = 'aleph:sources {--json : Emit JSON}';

    protected $description = 'List configured Aleph source installations.';

    public function handle(SourceInstallationQueries $queries): int
    {
        $data = array_map(static fn ($installation): array => $installation->toArray(), $queries->all());

        if ((bool) $this->option('json')) {
            return $this->json($data);
        }

        $this->table(['Installation', 'Connector', 'Label', 'Enabled'], array_map(
            fn (array $source): array => [
                $source['id'],
                $source['connector'],
                $source['label'],
                $this->display($source['enabled']),
            ],
            $data,
        ));

        return self::SUCCESS;
    }
}
