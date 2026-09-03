<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Console;

use DateTimeImmutable;
use Sifrious\Aleph\Connector\SourceInstallationQueries;

final class ShowSourceCommand extends AlephCommand
{
    protected $signature = 'aleph:source:show {installation : Source installation ID} {--json : Emit JSON}';

    protected $description = 'Show source configuration, health, streams, schedules, and recent runs.';

    public function handle(SourceInstallationQueries $queries): int
    {
        $id = (string) $this->argument('installation');
        $source = $queries->find($id, new DateTimeImmutable);

        if ($source === null) {
            $this->components->error("Source installation [{$id}] does not exist.");

            return self::FAILURE;
        }

        $data = $source->toArray();

        if ((bool) $this->option('json')) {
            return $this->json($data);
        }

        $this->table(['Field', 'Value'], [
            ['Installation', $source->installation->id],
            ['Connector', $source->installation->connectorId],
            ['Label', $source->installation->label],
            ['Enabled', $this->display($source->installation->enabled)],
            ['Health', $source->health->status->value],
            ['Streams', (string) count($source->streams)],
            ['Schedules', (string) count($source->schedules)],
            ['Recent runs', (string) count($source->runs)],
        ]);

        return self::SUCCESS;
    }
}
