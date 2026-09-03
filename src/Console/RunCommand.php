<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Console;

use Sifrious\Aleph\Connector\Capability;
use Sifrious\Aleph\Ingestion\LaunchAuthorization;
use Sifrious\Aleph\Ingestion\LaunchIngestion;
use Sifrious\Aleph\Ingestion\LaunchIngestionRequest;
use Throwable;

final class RunCommand extends AlephCommand
{
    protected $signature = 'aleph:run
        {installation : Source installation ID}
        {capability : Dispatchable capability ID}
        {source : Stable source reference}
        {--parameter=* : Input in key=value form}
        {--idempotency= : Required caller-owned idempotency key}
        {--actor= : Required stable actor reference}
        {--decision= : Required authorization decision reference}
        {--json : Emit JSON}';

    protected $description = 'Request one authorized, durable Aleph ingestion run.';

    public function handle(LaunchIngestion $launch): int
    {
        try {
            $result = $launch->launch(new LaunchIngestionRequest(
                sourceInstallationId: (string) $this->argument('installation'),
                sourceReference: (string) $this->argument('source'),
                capability: Capability::from((string) $this->argument('capability')),
                parameters: CommandInput::pairs((array) $this->option('parameter')),
                idempotencyKey: (string) $this->option('idempotency'),
                authorization: LaunchAuthorization::granted(
                    (string) $this->option('actor'),
                    (string) $this->option('decision'),
                ),
            ));
        } catch (Throwable $failure) {
            return $this->failure($failure);
        }

        $data = $result->toArray();

        if ((bool) $this->option('json')) {
            return $this->json($data);
        }

        $this->table(['Field', 'Value'], [
            ['Run', $result->run->id],
            ['Status', $result->run->status->value],
            ['Replayed', $this->display($result->replayed)],
        ]);

        return self::SUCCESS;
    }
}
