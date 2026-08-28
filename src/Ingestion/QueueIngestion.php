<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use Throwable;

final readonly class QueueIngestion
{
    public function __construct(
        private IngestionRuns $runs,
        private IngestionQueue $queue,
    ) {}

    public function dispatch(IngestionRun $run, ?QueueDispatchPolicy $policy = null): IngestionAttempt
    {
        $policy ??= QueueDispatchPolicy::forRun($run);
        $attempt = $this->runs->queueAttempt($run, $policy);

        if ($attempt->queueJobId !== null) {
            return $attempt;
        }

        $queued = new QueuedIngestion($run, $attempt, $policy, $attempt->tags);

        try {
            $receipt = $this->queue->dispatch($queued);
        } catch (Throwable $failure) {
            $this->runs->failQueuedAttempt($attempt, $failure);

            throw $failure;
        }

        return $this->runs->recordQueueReceipt($attempt, $receipt);
    }
}
