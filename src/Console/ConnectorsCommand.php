<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Console;

use Sifrious\Aleph\Connector\ConnectorCatalogue;

final class ConnectorsCommand extends AlephCommand
{
    protected $signature = 'aleph:connectors {connector? : Show one connector} {--json : Emit JSON}';

    protected $description = 'List registered Aleph connectors and their capabilities.';

    public function handle(ConnectorCatalogue $catalogue): int
    {
        $id = $this->argument('connector');
        if (is_string($id) && $id !== '') {
            $entry = $catalogue->find($id);

            if ($entry === null) {
                $this->components->error("Connector [{$id}] is not registered.");

                return self::FAILURE;
            }

            $entries = [$entry];
        } else {
            $entries = $catalogue->entries();
        }

        $data = array_map(static fn ($entry): array => $entry->toArray(), $entries);

        if ((bool) $this->option('json')) {
            return $this->json(is_string($id) && $id !== '' ? $data[0] : $data);
        }

        $this->table(
            ['Connector', 'Name', 'Enabled', 'Capabilities'],
            array_map(fn (array $entry): array => [
                $entry['id'],
                $entry['name'],
                $this->display($entry['enabled']),
                implode(', ', $entry['capabilities']),
            ], $data),
        );

        return self::SUCCESS;
    }
}
