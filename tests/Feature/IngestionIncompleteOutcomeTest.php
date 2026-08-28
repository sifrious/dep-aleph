<?php

declare(strict_types=1);

use Sifrious\Aleph\Ingestion\Capability;
use Sifrious\Aleph\Ingestion\FailureOrigin;
use Sifrious\Aleph\Ingestion\IngestionRunQueries;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\RunFailure;

it('states how each incomplete provider outcome can recover', function (
    RunFailure $failure,
    string $expectedAction,
): void {
    $runs = app(IngestionRuns::class);
    $run = $runs->start('provider:account/'.$failure->kind, Capability::IncrementalSync, []);
    $attempt = $runs->beginAttempt($run);
    $runs->failAttempt($run, $attempt, $failure);
    $outcome = app(IngestionRunQueries::class)->find($run->id)?->toArray();

    expect($outcome['next_action'] ?? null)->toBe($expectedAction)
        ->and($outcome['error_count'] ?? null)->toBe(1)
        ->and($outcome['failures'][0]['category'] ?? null)->toBe($failure->kind)
        ->and($outcome['failures'][0]['origin'] ?? null)->toBe($failure->origin->value);
})->with([
    'rate limited' => [new RunFailure('rate_limited', 'Try later.', true), 'retry'],
    'authentication blocked' => [new RunFailure('authentication_blocked', 'Replace credentials.', false), 'provide_credentials'],
    'provider action required' => [new RunFailure('provider_rejected', 'Resolve provider state.', false), 'user_action'],
    'queue retry' => [new RunFailure('heartbeat_timeout', 'Worker disappeared.', true, [], FailureOrigin::Queue), 'retry'],
]);

it('preserves remaining partitions and checkpoint detail for a resumable partial run', function (): void {
    $runs = app(IngestionRuns::class);
    $run = $runs->start('linear:workspace/example', Capability::IncrementalSync, [], checkpoint: ['cursor' => 'page-2']);
    $attempt = $runs->beginAttempt($run);
    $remainingWork = [
        ['partition_key' => 'team:T2', 'checkpoint' => ['cursor' => 'page-2']],
    ];
    $runs->failAttempt(
        $run,
        $attempt,
        new RunFailure('rate_limited', 'Try later.', true),
        partial: true,
        remainingWork: $remainingWork,
        warningCount: 1,
    );
    $outcome = app(IngestionRunQueries::class)->find($run->id)?->toArray();

    expect($outcome['next_action'] ?? null)->toBe('resume')
        ->and($outcome['remaining_work'] ?? null)->toBe($remainingWork)
        ->and($outcome['checkpoint'] ?? null)->toBe(['cursor' => 'page-2'])
        ->and($outcome['warning_count'] ?? null)->toBe(1);
});

it('distinguishes interrupted and canceled recovery without queue inference', function (): void {
    $runs = app(IngestionRuns::class);
    $interrupted = $runs->start('github:repository/one', Capability::IncrementalSync, []);
    $runs->interrupt($interrupted, 'Process stopped.');
    $canceled = $runs->start('github:repository/two', Capability::IncrementalSync, []);
    $runs->cancel($canceled, 'Canceled by operator.', [['partition_key' => 'issues']]);
    $queries = app(IngestionRunQueries::class);

    expect($queries->find($interrupted->id)?->toArray()['next_action'] ?? null)->toBe('resume')
        ->and($queries->find($canceled->id)?->toArray()['next_action'] ?? null)->toBe('restart')
        ->and($queries->find($canceled->id)?->failures)->toHaveCount(1);
});
