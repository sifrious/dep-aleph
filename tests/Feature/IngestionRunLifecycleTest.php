<?php

declare(strict_types=1);

use Sifrious\Aleph\Connector\Capability as ConnectorCapability;
use Sifrious\Aleph\Connector\ConnectorDispatcher;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\Values\OperationRequest;
use Sifrious\Aleph\Ingestion\Capability;
use Sifrious\Aleph\Ingestion\IngestionRunQueries;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\InvalidRunTransition;
use Sifrious\Aleph\Ingestion\LandingProviderRunAdapter;
use Sifrious\Aleph\Ingestion\RunCompleteness;
use Sifrious\Aleph\Ingestion\RunFailure;
use Sifrious\Aleph\Ingestion\RunStatus;
use Sifrious\Aleph\Testing\Fakes\DiscoveryAndDownloadConnector;

it('drives a fake connector through the same durable lifecycle', function (): void {
    app(ConnectorRegistry::class)->register(new DiscoveryAndDownloadConnector);
    $runs = app(IngestionRuns::class);
    $run = $runs->start(
        'archive-drop:root',
        Capability::DiscoverSources,
        [],
        connectorId: 'archive-drop',
        idempotencyKey: 'archive-drop:root:discovery',
    );
    $attempt = $runs->beginAttempt($run);
    $sources = app(ConnectorDispatcher::class)->dispatch(
        'archive-drop',
        ConnectorCapability::DiscoversSources,
        new OperationRequest('archive-drop:root'),
    );
    expect($sources)->toHaveCount(2);
    $runs->succeedAttempt($run, $attempt, ['sources_discovered' => 2]);
    $inspected = app(IngestionRunQueries::class)->find($run->id);

    expect($inspected?->run->status)->toBe(RunStatus::Completed)
        ->and($inspected?->run->stats)->toBe(['sources_discovered' => 2])
        ->and($inspected?->attempts)->toHaveCount(1);
});

it('records a successful connector attempt with inspectable identity and progress', function (): void {
    $runs = app(IngestionRuns::class);
    $run = $runs->start(
        sourceReference: 'slack:workspace/T1',
        capability: Capability::IncrementalSync,
        parameters: ['scope' => 'channels'],
        connectorId: 'slack',
        sourceInstallationId: '01K4SOURCEINSTALLATION00001',
        idempotencyKey: 'slack:T1:2026-08-28T10',
        checkpoint: ['cursor' => null],
    );
    $attempt = $runs->beginAttempt($run);

    $runs->recordProgress(
        $run,
        $attempt,
        ['cursor' => 'page-2'],
        ['observed' => 5, 'accepted' => 4],
        ['funes:observation/01K4ACCEPTED'],
    );
    $current = app(IngestionRunQueries::class)->find($run->id);

    expect($current)->not->toBeNull()
        ->and($current?->run->connectorId)->toBe('slack')
        ->and($current?->run->sourceInstallationId)->toBe('01K4SOURCEINSTALLATION00001')
        ->and($current?->run->checkpoint)->toBe(['cursor' => 'page-2'])
        ->and($current?->attempts)->toHaveCount(1)
        ->and($current?->toArray()['next_action'])->toBe('none');

    $runs->succeedAttempt(
        $current->run,
        $current->attempts[0],
        ['observed' => 5, 'accepted' => 5],
        ['funes:observation/01K4ACCEPTED', 'funes:observation/01K4SECOND'],
    );
    $completed = app(IngestionRunQueries::class)->find($run->id);

    expect($completed?->run->status)->toBe(RunStatus::Completed)
        ->and($completed?->run->completeness)->toBe(RunCompleteness::Complete)
        ->and($completed?->run->acceptedReferences)->toBe([
            'funes:observation/01K4ACCEPTED',
            'funes:observation/01K4SECOND',
        ])
        ->and($completed?->attempts[0]->status)->toBe(RunStatus::Completed)
        ->and($completed?->toArray()['next_action'])->toBe('none');
});

it('retries a partial provider failure from the committed checkpoint without duplicate history references', function (): void {
    $runs = app(IngestionRuns::class);
    $run = $runs->start(
        'linear:workspace/example',
        Capability::IncrementalSync,
        [],
        connectorId: 'linear',
        idempotencyKey: 'linear:example:window-1',
        checkpoint: ['cursor' => null],
    );
    $firstAttempt = $runs->beginAttempt($run);
    $runs->recordProgress(
        $run,
        $firstAttempt,
        ['cursor' => 'cursor-2'],
        ['accepted' => 3],
        ['funes:observation/01K4FIRST'],
    );
    $current = app(IngestionRunQueries::class)->find($run->id);
    $runs->failAttempt(
        $current->run,
        $current->attempts[0],
        new RunFailure('rate_limited', 'Retry after 60 seconds.', true, ['retry_after' => 60]),
        ['accepted' => 3, 'failed' => 1],
        ['funes:observation/01K4FIRST'],
        partial: true,
    );
    $partial = app(IngestionRunQueries::class)->find($run->id);

    expect($partial?->run->status)->toBe(RunStatus::Partial)
        ->and($partial?->run->completeness)->toBe(RunCompleteness::Partial)
        ->and($partial?->run->failure?->details)->toBe(['retry_after' => 60])
        ->and($partial?->toArray()['next_action'])->toBe('resume');

    $secondAttempt = $runs->beginAttempt($partial->run);
    $resumed = app(IngestionRunQueries::class)->find($run->id);

    expect($secondAttempt->number)->toBe(2)
        ->and($secondAttempt->checkpoint)->toBe(['cursor' => 'cursor-2'])
        ->and($resumed?->attempts)->toHaveCount(2);

    $runs->succeedAttempt(
        $resumed->run,
        $resumed->attempts[1],
        ['accepted' => 4],
        ['funes:observation/01K4FIRST', 'funes:observation/01K4SECOND'],
    );
    $completed = app(IngestionRunQueries::class)->find($run->id);

    expect($completed?->run->acceptedReferences)->toBe([
        'funes:observation/01K4FIRST',
        'funes:observation/01K4SECOND',
    ])
        ->and($completed?->attempts)->toHaveCount(2);
});

it('retains a fatal provider failure and refuses another attempt', function (): void {
    $runs = app(IngestionRuns::class);
    $run = $runs->start('dns:account/7', Capability::IncrementalSync, [], connectorId: 'dns');
    $attempt = $runs->beginAttempt($run);
    $runs->failAttempt($run, $attempt, new RunFailure('authentication', 'Token rejected.', false));
    $failed = app(IngestionRunQueries::class)->find($run->id);

    expect($failed?->run->failure?->kind)->toBe('authentication')
        ->and($failed?->toArray()['next_action'])->toBe('provide_credentials')
        ->and(fn () => $runs->beginAttempt($failed->run))->toThrow(InvalidRunTransition::class);
});

it('returns a presentation neutral read model through two host adapters', function (): void {
    $run = app(IngestionRuns::class)->start(
        'github:repository/R1',
        Capability::IncrementalSync,
        [],
        connectorId: 'github',
    );
    $queries = app(IngestionRunQueries::class);
    $browserAdapter = static fn (string $id): ?array => $queries->find($id)?->toArray();
    $operatorAdapter = static fn (string $id): ?array => $queries->find($id)?->toArray();

    expect($browserAdapter($run->id))->toBe($operatorAdapter($run->id))
        ->and(serialize($browserAdapter($run->id)))->not->toContain('App\\');
});

it('imports every Landing provider audit shape through one package boundary', function (
    string $connector,
    Capability $capability,
    string $source,
): void {
    $legacy = (new LandingProviderRunAdapter)->adapt(
        $connector,
        [
            'id' => 12,
            'scope' => 'account',
            'label' => 'Fixture run',
            'status' => 'succeeded',
            'targets' => [['id' => 'target-1']],
            'stats' => ['accepted' => 4, 'provider_note' => 'not-a-counter'],
            'error' => null,
            'started_at' => '2026-08-28T08:00:00+00:00',
            'finished_at' => '2026-08-28T08:01:00+00:00',
        ],
        $source,
        $capability,
    );
    $run = app(IngestionRuns::class)->import($legacy);

    expect($run->connectorId)->toBe($connector)
        ->and($run->sourceReference)->toBe($source)
        ->and($run->parameters['targets'])->toBe([['id' => 'target-1']])
        ->and($run->stats)->toBe(['accepted' => 4])
        ->and($run->status)->toBe(RunStatus::Completed);
})->with([
    'SlackSyncRun' => ['slack', Capability::IncrementalSync, 'slack:workspace/T1'],
    'GithubSyncRun' => ['github', Capability::IncrementalSync, 'github:repository/R1'],
    'LinearSyncRun' => ['linear', Capability::IncrementalSync, 'linear:workspace/example'],
    'DomainSyncRun' => ['dns', Capability::IncrementalSync, 'dns:account/7'],
]);
