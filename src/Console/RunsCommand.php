<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Console;

use Sifrious\Aleph\Ingestion\IngestionRunQueries;
use Throwable;

final class RunsCommand extends AlephCommand
{
    protected $signature = 'aleph:runs {--limit=25 : Number of recent runs, from 1 to 100} {--json : Emit JSON}';

    protected $description = 'List recent Aleph ingestion runs.';

    public function handle(IngestionRunQueries $queries): int
    {
        try {
            $runs = $queries->recent((int) $this->option('limit'));
        } catch (Throwable $failure) {
            return $this->failure($failure);
        }

        $data = array_map(static fn ($run): array => $run->toArray(), $runs);

        if ((bool) $this->option('json')) {
            return $this->json($data);
        }

        $this->table(['Run', 'Source', 'Capability', 'Status', 'Next action'], array_map(
            static fn (array $run): array => [
                $run['id'],
                $run['source'],
                $run['capability'],
                $run['status'],
                $run['next_action'],
            ],
            $data,
        ));

        return self::SUCCESS;
    }
}
