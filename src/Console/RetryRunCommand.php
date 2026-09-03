<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Console;

use Sifrious\Aleph\Ingestion\RetryIngestion;
use Sifrious\Aleph\Ingestion\RetryIngestionRequest;
use Throwable;

final class RetryRunCommand extends AlephCommand
{
    protected $signature = 'aleph:run:retry
        {run : Ingestion run ID}
        {attempt : Failed attempt ID}
        {--reason= : Required operator reason}
        {--partition= : Optional remaining partition key}
        {--json : Emit JSON}';

    protected $description = 'Retry one retryable Aleph ingestion attempt.';

    public function handle(RetryIngestion $retry): int
    {
        try {
            $reason = trim((string) $this->option('reason'));

            if ($reason === '') {
                throw new \InvalidArgumentException('Retry requires an operator reason.');
            }

            $result = $retry->retry(new RetryIngestionRequest(
                (string) $this->argument('run'),
                (string) $this->argument('attempt'),
                $reason,
                $this->stringOption('partition'),
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
            ['Attempt', $result->attempt->id],
            ['Replayed', $this->display($result->replayed)],
        ]);

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
