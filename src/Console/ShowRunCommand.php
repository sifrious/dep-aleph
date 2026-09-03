<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Console;

use Sifrious\Aleph\Ingestion\IngestionRunQueries;

final class ShowRunCommand extends AlephCommand
{
    protected $signature = 'aleph:run:show {run : Ingestion run ID} {--json : Emit JSON}';

    protected $description = 'Show one Aleph ingestion run and its recovery state.';

    public function handle(IngestionRunQueries $queries): int
    {
        $id = (string) $this->argument('run');
        $run = $queries->find($id);

        if ($run === null) {
            $this->components->error("Ingestion run [{$id}] does not exist.");

            return self::FAILURE;
        }

        $data = $run->toArray();

        if ((bool) $this->option('json')) {
            return $this->json($data);
        }

        $rows = [];

        foreach ($data as $field => $value) {
            $rows[] = [str_replace('_', ' ', ucfirst((string) $field)), $this->display($value)];
        }

        $this->table(['Field', 'Value'], $rows);

        return self::SUCCESS;
    }
}
