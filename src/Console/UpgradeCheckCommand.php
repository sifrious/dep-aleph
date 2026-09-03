<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Console;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Testing\Contracts\ConnectorContract;

final class UpgradeCheckCommand extends AlephCommand
{
    protected $signature = 'aleph:upgrade:check {--json : Emit JSON}';

    protected $description = 'Check Aleph migrations, configuration, and registered connector contracts.';

    public function handle(DatabaseManager $databases, Config $config, ConnectorRegistry $connectors): int
    {
        $connection = $databases->connection();
        $migrationPaths = glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [];
        $requiredMigrations = array_map(
            static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
            $migrationPaths,
        );
        $ran = $connection->getSchemaBuilder()->hasTable('migrations')
            ? $connection->table('migrations')->pluck('migration')->map(strval(...))->all()
            : [];
        $missingMigrations = array_values(array_diff($requiredMigrations, $ran));
        sort($missingMigrations);

        $requiredConfig = ['http', 'normalization', 'web_sources'];
        $missingConfig = array_values(array_filter(
            $requiredConfig,
            static fn (string $key): bool => $config->get('aleph.'.$key) === null,
        ));
        $connectorViolations = [];

        foreach ($connectors->all() as $connector) {
            $violations = ConnectorContract::violations($connector);

            if ($violations !== []) {
                $connectorViolations[$connector->id()] = $violations;
            }
        }

        $result = [
            'ready' => $missingMigrations === [] && $missingConfig === [] && $connectorViolations === [],
            'missing_migrations' => $missingMigrations,
            'missing_configuration' => $missingConfig,
            'connector_violations' => $connectorViolations,
        ];

        if ((bool) $this->option('json')) {
            $this->json($result);
        } else {
            $this->line($result['ready'] ? 'Aleph is ready for this version.' : 'Aleph needs upgrade work.');

            foreach ($missingMigrations as $migration) {
                $this->components->warn("Pending migration: {$migration}");
            }

            foreach ($missingConfig as $key) {
                $this->components->warn("Missing configuration: aleph.{$key}");
            }

            foreach ($connectorViolations as $connector => $violations) {
                foreach ($violations as $violation) {
                    $this->components->warn("Connector {$connector}: {$violation}");
                }
            }
        }

        return $result['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
