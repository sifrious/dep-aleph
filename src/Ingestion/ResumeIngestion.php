<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

final readonly class ResumeIngestion
{
    public function __construct(
        private IngestionRuns $runs,
        private SourceStreams $streams,
        private IngestionCheckpoints $checkpoints,
        private ContinuationLeases $leases,
        private QueueIngestion $queue,
    ) {}

    public function resume(ResumeIngestionRequest $request): ResumeIngestionResult
    {
        $run = $this->runs->find($request->runId);
        $stream = $this->streams->find($request->sourceStreamId);

        if ($run === null || $stream === null) {
            throw new ResumeRejected('source_not_found', 'The run or source stream could not be found.');
        }

        if (! in_array($run->status, [RunStatus::Interrupted, RunStatus::Partial], true)) {
            throw new ResumeRejected('run_not_resumable', 'Only an interrupted or partial run can resume.');
        }

        if (! $stream->enabled || $stream->sourceInstallationId !== $run->sourceInstallationId) {
            throw new ResumeRejected('stream_not_resumable', 'The source stream is disabled or belongs to another installation.');
        }

        $partitions = array_values(array_unique(array_filter(
            array_map(trim(...), $request->partitionKeys),
            static fn (string $partition): bool => $partition !== '',
        )));

        if ($partitions === [] || count($partitions) > $request->budget->maxPartitions) {
            throw new ResumeRejected('partition_budget_exceeded', 'Requested partitions must fit the continuation budget.');
        }

        $activeAttempt = $this->runs->activeAttempt($run);

        if ($activeAttempt !== null && ($activeAttempt->checkpoint['lease_owner'] ?? null) !== $request->leaseOwner) {
            throw new ResumeRejected('resume_already_active', 'An active resume attempt belongs to another lease owner.');
        }

        $continuations = [];

        foreach ($partitions as $partition) {
            $continuations[] = new IngestionContinuation(
                $partition,
                $this->checkpoints->latest($stream, $run->capability, $partition),
            );
        }
        $leases = $this->leases->acquireMany(
            $stream,
            $run->capability,
            $partitions,
            $run,
            $request->leaseOwner,
            $request->requestedAt,
            $request->leaseSeconds,
        );

        $checkpoint = [
            'lease_owner' => $request->leaseOwner,
            'continuations' => array_map(
                static fn (IngestionContinuation $continuation): array => $continuation->toArray(),
                $continuations,
            ),
            'budget' => $request->budget->toArray(),
        ];
        $policy = QueueDispatchPolicy::forRun($run);
        $prepared = $this->runs->queueAttempt($run, $policy, $checkpoint);
        $attempt = $prepared->queueJobId === null ? $this->queue->dispatch($run, $policy) : $prepared;

        return new ResumeIngestionResult($run, $attempt, $continuations, $leases, $request->budget);
    }
}
