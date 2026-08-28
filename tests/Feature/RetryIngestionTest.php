<?php

declare(strict_types=1);

use Sifrious\Aleph\Ingestion\Capability;
use Sifrious\Aleph\Ingestion\IngestionQueue;
use Sifrious\Aleph\Ingestion\IngestionRunQueries;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\QueuedIngestion;
use Sifrious\Aleph\Ingestion\QueueIngestion;
use Sifrious\Aleph\Ingestion\QueueReceipt;
use Sifrious\Aleph\Ingestion\RetryIngestion;
use Sifrious\Aleph\Ingestion\RetryIngestionRequest;
use Sifrious\Aleph\Ingestion\RetryRejected;
use Sifrious\Aleph\Ingestion\RunFailure;

final class RetryFakeQueue implements IngestionQueue
{
    public int $dispatches = 0;

    public function dispatch(QueuedIngestion $ingestion): QueueReceipt
    {
        $this->dispatches++;

        return new QueueReceipt('retry-job-'.$ingestion->attempt->number);
    }
}

it('retries one partition from durable state and preserves every prior attempt', function (): void {
    $runs = app(IngestionRuns::class);
    $run = $runs->start(
        'linear:workspace/example',
        Capability::IncrementalSync,
        ['scope' => 'issues'],
        connectorId: 'linear',
        idempotencyKey: 'linear:window-1',
        checkpoint: ['cursor' => 'page-2'],
    );
    $first = $runs->beginAttempt($run);
    $remaining = [['partition_key' => 'team:T2', 'checkpoint' => ['cursor' => 'page-2']]];
    $runs->failAttempt(
        $run,
        $first,
        new RunFailure('rate_limited', 'Try later.', true),
        ['accepted' => 5],
        partial: true,
        remainingWork: $remaining,
        partitionKey: 'team:T2',
    );
    $failedRun = $runs->find($run->id);
    $failedAttempt = $runs->attempt($first->id);
    $queue = new RetryFakeQueue;
    $retry = new RetryIngestion($runs, new QueueIngestion($runs, $queue));
    $request = new RetryIngestionRequest($run->id, $first->id, 'Operator retry', 'team:T2');
    $result = $retry->retry($request);
    $replayed = $retry->retry($request);
    $timeline = app(IngestionRunQueries::class)->find($run->id);

    expect($failedRun?->parameters)->toBe(['scope' => 'issues'])
        ->and($failedAttempt?->retryable)->toBeTrue()
        ->and($result->attempt->number)->toBe(2)
        ->and($result->attempt->retryOfId)->toBe($first->id)
        ->and($result->attempt->retryReason)->toBe('Operator retry')
        ->and($result->attempt->partitionKey)->toBe('team:T2')
        ->and($result->attempt->checkpoint)->toBe(['cursor' => 'page-2'])
        ->and($result->attempt->queueJobId)->toBe('retry-job-2')
        ->and($replayed->attempt->id)->toBe($result->attempt->id)
        ->and($replayed->replayed)->toBeTrue()
        ->and($queue->dispatches)->toBe(1)
        ->and($timeline?->attempts)->toHaveCount(2)
        ->and($timeline?->failures[0]->partitionKey)->toBe('team:T2')
        ->and($timeline?->failures[0]->resolvedAt)->not->toBeNull();
});

it('refuses fatal failures, active backoff, and partitions outside remaining work', function (): void {
    $runs = app(IngestionRuns::class);
    $fatalRun = $runs->start('dns:account/1', Capability::IncrementalSync, []);
    $fatalAttempt = $runs->beginAttempt($fatalRun);
    $runs->failAttempt($fatalRun, $fatalAttempt, new RunFailure('authentication_blocked', 'Replace token.', false));
    $backoffRun = $runs->start('linear:workspace/example', Capability::IncrementalSync, []);
    $backoffAttempt = $runs->beginAttempt($backoffRun);
    $runs->failAttempt(
        $backoffRun,
        $backoffAttempt,
        new RunFailure('rate_limited', 'Try later.', true),
        partial: true,
        remainingWork: [['partition_key' => 'team:T2']],
        backoffUntil: new DateTimeImmutable('2026-08-28T16:00:00+00:00'),
    );
    $fatal = $runs->find($fatalRun->id);
    $fatalFailure = $runs->attempt($fatalAttempt->id);
    $backoff = $runs->find($backoffRun->id);
    $backoffFailure = $runs->attempt($backoffAttempt->id);

    expect(fn () => $runs->retryAttempt($fatal, $fatalFailure, 'retry'))
        ->toThrow(RetryRejected::class, 'not retryable')
        ->and(fn () => $runs->retryAttempt(
            $backoff,
            $backoffFailure,
            'retry',
            'team:T2',
            new DateTimeImmutable('2026-08-28T15:00:00+00:00'),
        ))->toThrow(RetryRejected::class, 'backoff period')
        ->and(fn () => $runs->retryAttempt(
            $backoff,
            $backoffFailure,
            'retry',
            'team:T9',
            new DateTimeImmutable('2026-08-28T17:00:00+00:00'),
        ))->toThrow(RetryRejected::class, 'not listed as remaining work');
});
