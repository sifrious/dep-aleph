<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

final readonly class RetryIngestion
{
    public function __construct(
        private IngestionRuns $runs,
        private QueueIngestion $queue,
    ) {}

    public function retry(RetryIngestionRequest $request): RetryIngestionResult
    {
        $run = $this->runs->find($request->runId);
        $attempt = $this->runs->attempt($request->attemptId);

        if ($run === null || $attempt === null) {
            throw new RetryRejected('attempt_not_found', 'The requested run and attempt could not be found.');
        }

        $existing = $this->runs->retryFor($attempt);
        $this->runs->retryAttempt($run, $attempt, $request->reason, $request->partitionKey);
        $queued = $this->queue->dispatch($run);

        return new RetryIngestionResult($run, $queued, $existing !== null);
    }
}
