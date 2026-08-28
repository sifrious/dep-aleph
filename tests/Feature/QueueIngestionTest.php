<?php

declare(strict_types=1);

use Sifrious\Aleph\Ingestion\Capability;
use Sifrious\Aleph\Ingestion\IngestionQueue;
use Sifrious\Aleph\Ingestion\IngestionRunQueries;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\QueueClass;
use Sifrious\Aleph\Ingestion\QueuedIngestion;
use Sifrious\Aleph\Ingestion\QueueDispatchPolicy;
use Sifrious\Aleph\Ingestion\QueueIngestion;
use Sifrious\Aleph\Ingestion\QueueReceipt;
use Sifrious\Aleph\Ingestion\RunStatus;

final class FakeIngestionQueue implements IngestionQueue
{
    /** @var array<string, QueuedIngestion> */
    public array $jobs = [];

    /** @var array<string, int> */
    public array $dispatchesByRateKey = [];

    /** @var array<string, int> */
    public array $activeByConcurrencyKey = [];

    public bool $attemptExistedAtDispatch = false;

    public function __construct(private readonly IngestionRuns $runs) {}

    public function dispatch(QueuedIngestion $ingestion): QueueReceipt
    {
        $this->attemptExistedAtDispatch = $this->runs->attempt($ingestion->attempt->id) !== null;
        $policy = $ingestion->policy;
        $active = $this->activeByConcurrencyKey[$policy->concurrencyKey] ?? 0;
        $dispatched = $this->dispatchesByRateKey[$policy->rateLimitKey] ?? 0;

        if ($active >= $policy->maxConcurrency) {
            throw new RuntimeException('Concurrency limit reached.');
        }

        if ($dispatched >= $policy->maxPerMinute) {
            throw new RuntimeException('Rate limit reached.');
        }

        $jobId = 'fake-job-'.(count($this->jobs) + 1);
        $this->jobs[$jobId] = $ingestion;
        $this->activeByConcurrencyKey[$policy->concurrencyKey] = $active + 1;
        $this->dispatchesByRateKey[$policy->rateLimitKey] = $dispatched + 1;

        return new QueueReceipt($jobId);
    }

    public function prune(): void
    {
        $this->jobs = [];
    }

    public function complete(string $jobId): void
    {
        $job = $this->jobs[$jobId];
        $key = $job->policy->concurrencyKey;
        $this->activeByConcurrencyKey[$key]--;
        unset($this->jobs[$jobId]);
    }
}

function queueFixture(string $source = 'slack:workspace/T1'): array
{
    $runs = app(IngestionRuns::class);
    $run = $runs->start(
        sourceReference: $source,
        capability: Capability::IncrementalSync,
        parameters: ['scope' => 'channels'],
        connectorId: 'slack',
        sourceInstallationId: 'slack-installation-T1',
    );
    $queue = new FakeIngestionQueue($runs);

    return [$runs, $run, $queue, new QueueIngestion($runs, $queue)];
}

it('persists a tagged prioritized attempt before dispatching runtime work', function (): void {
    [$runs, $run, $queue, $dispatcher] = queueFixture();
    $policy = new QueueDispatchPolicy(
        QueueClass::Ingest,
        80,
        'slack-account:T1',
        1,
        'slack-account:T1',
        30,
    );

    $attempt = $dispatcher->dispatch($run, $policy);
    $payload = $queue->jobs['fake-job-1']->toArray();

    expect($queue->attemptExistedAtDispatch)->toBeTrue()
        ->and($attempt->status)->toBe(RunStatus::Pending)
        ->and($attempt->queueJobId)->toBe('fake-job-1')
        ->and($attempt->queue)->toBe(QueueClass::Ingest)
        ->and($attempt->priority)->toBe(80)
        ->and($attempt->tags)->toBe([
            'connector:slack',
            'source-installation:slack-installation-T1',
            'run:'.$run->id,
            'attempt:'.$attempt->id,
        ])
        ->and($payload['policy'])->toBe($policy->toArray())
        ->and($payload['attempt_id'])->toBe($attempt->id)
        ->and($runs->attempt($attempt->id)?->queuedAt)->not->toBeNull();
});

it('retains attempt observability after runtime queue records are pruned', function (): void {
    [, $run, $queue, $dispatcher] = queueFixture();
    $attempt = $dispatcher->dispatch($run);

    $queue->prune();
    $read = app(IngestionRunQueries::class)->find($run->id);

    expect($queue->jobs)->toBe([])
        ->and($read?->attempts)->toHaveCount(1)
        ->and($read?->attempts[0]->id)->toBe($attempt->id)
        ->and($read?->attempts[0]->queueJobId)->toBe('fake-job-1')
        ->and($read?->attempts[0]->dispatchPolicy?->maxConcurrency)->toBe(1);
});

it('records worker identity and expires a stale heartbeat into retry lineage', function (): void {
    [$runs, $run, , $dispatcher] = queueFixture();
    $queued = $dispatcher->dispatch($run);
    $running = $runs->startQueuedAttempt($queued, 'worker:ingest/7');
    $stale = $runs->heartbeat($running, new DateTimeImmutable('2026-08-28T10:00:00+00:00'));

    $expired = $runs->expireStaleAttempts(new DateTimeImmutable('2026-08-28T10:01:00+00:00'));
    $failed = app(IngestionRunQueries::class)->find($run->id);
    $retry = $runs->queueAttempt($failed->run, QueueDispatchPolicy::forRun($failed->run));

    expect($stale->workerId)->toBe('worker:ingest/7')
        ->and($expired)->toBe([$queued->id])
        ->and($failed?->attempts[0]->status)->toBe(RunStatus::Failed)
        ->and($failed?->attempts[0]->failure?->kind)->toBe('heartbeat_timeout')
        ->and($failed?->toArray()['next_action'])->toBe('retry')
        ->and($retry->number)->toBe(2)
        ->and($retry->checkpoint)->toBe($failed?->run->checkpoint);
});

it('passes concurrency and rate limits to a runtime independent of Laravel queue tables', function (): void {
    [$runs, $firstRun, $queue, $dispatcher] = queueFixture();
    $policy = new QueueDispatchPolicy(QueueClass::Ingest, 50, 'account:T1', 1, 'account:T1', 1);
    $dispatcher->dispatch($firstRun, $policy);
    $secondRun = $runs->start(
        'slack:workspace/T1:second',
        Capability::IncrementalSync,
        [],
        connectorId: 'slack',
        sourceInstallationId: 'slack-installation-T1',
    );

    expect(fn () => $dispatcher->dispatch($secondRun, $policy))
        ->toThrow(RuntimeException::class, 'Concurrency limit reached.')
        ->and($runs->attempts($secondRun)[0]->failure?->kind)->toBe('queue_dispatch')
        ->and($runs->attempts($secondRun)[0]->failure?->retryable)->toBeTrue()
        ->and($queue->jobs)->toHaveCount(1);
});

it('passes a per-account rate budget independently of active concurrency', function (): void {
    [$runs, $firstRun, $queue, $dispatcher] = queueFixture();
    $policy = new QueueDispatchPolicy(QueueClass::Ingest, 50, 'account:T1', 1, 'account:T1', 1);
    $dispatcher->dispatch($firstRun, $policy);
    $queue->complete('fake-job-1');
    $secondRun = $runs->start(
        'slack:workspace/T1:second',
        Capability::IncrementalSync,
        [],
        connectorId: 'slack',
        sourceInstallationId: 'slack-installation-T1',
    );

    expect(fn () => $dispatcher->dispatch($secondRun, $policy))
        ->toThrow(RuntimeException::class, 'Rate limit reached.')
        ->and($runs->attempts($secondRun)[0]->failure?->kind)->toBe('queue_dispatch');
});

it('classifies media work separately from ordinary ingestion', function (): void {
    $runs = app(IngestionRuns::class);
    $run = $runs->start('artifact:file/1', Capability::DownloadArtifact, [], connectorId: 'archive');

    expect(QueueDispatchPolicy::forRun($run)->queue)->toBe(QueueClass::Media)
        ->and(QueueClass::Normalize->value)->toBe('normalize')
        ->and(QueueClass::Agentic->value)->toBe('agentic');
});
