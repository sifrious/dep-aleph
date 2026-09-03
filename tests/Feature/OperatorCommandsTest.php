<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;
use Sifrious\Aleph\Ingestion\Capability;
use Sifrious\Aleph\Ingestion\CheckpointRule;
use Sifrious\Aleph\Ingestion\CheckpointValue;
use Sifrious\Aleph\Ingestion\IngestionCheckpoints;
use Sifrious\Aleph\Ingestion\IngestionQueue;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\LaunchIngestionResult;
use Sifrious\Aleph\Ingestion\ManualIngestionDispatcher;
use Sifrious\Aleph\Ingestion\QueuedIngestion;
use Sifrious\Aleph\Ingestion\QueueReceipt;
use Sifrious\Aleph\Ingestion\RunFailure;
use Sifrious\Aleph\Ingestion\SourceStreams;
use Sifrious\Aleph\Testing\Fakes\ConfiguringConnector;
use Sifrious\Aleph\Testing\Fakes\DiscoveryAndDownloadConnector;

final class OperatorRecordingDispatcher implements ManualIngestionDispatcher
{
    /** @var list<string> */
    public array $runIds = [];

    public function dispatch(LaunchIngestionResult $launch): void
    {
        $this->runIds[] = $launch->run->id;
    }
}

final class OperatorRecordingQueue implements IngestionQueue
{
    /** @var list<string> */
    public array $attemptIds = [];

    public function dispatch(QueuedIngestion $ingestion): QueueReceipt
    {
        $this->attemptIds[] = $ingestion->attempt->id;

        return new QueueReceipt('operator-job-'.$ingestion->attempt->number);
    }
}

/**
 * @param  array<string, mixed>  $parameters
 */
function callOperatorCommand(string $command, array $parameters = []): string
{
    $exit = Artisan::call($command, $parameters);

    if ($exit !== 0) {
        throw new RuntimeException(Artisan::output());
    }

    return Artisan::output();
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array<mixed>
 */
function callJsonOperatorCommand(string $command, array $parameters = []): array
{
    $output = callOperatorCommand($command, [...$parameters, '--json' => true]);
    $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

    expect($decoded)->toBeArray();

    return $decoded;
}

/**
 * @return array{object, OperatorRecordingDispatcher}
 */
function operatorLaunchFixture(): array
{
    $connector = new DiscoveryAndDownloadConnector;
    app(ConnectorRegistry::class)->register($connector);
    $installation = app(ConnectorInstallations::class)->create($connector, 'Operator archive');
    $dispatcher = new OperatorRecordingDispatcher;
    app()->instance(ManualIngestionDispatcher::class, $dispatcher);

    return [$installation, $dispatcher];
}

/**
 * @return array{object, object, object}
 */
function operatorResumeFixture(): array
{
    $connector = new DiscoveryAndDownloadConnector;
    app(ConnectorRegistry::class)->register($connector);
    $installation = app(ConnectorInstallations::class)->create($connector, 'Operator resume source');
    $stream = app(SourceStreams::class)->create($installation->id, 'channel:C1', 'slack_channel', 'C1');
    $runs = app(IngestionRuns::class);
    $run = $runs->start(
        'slack:channel/C1',
        Capability::Backfill,
        ['oldest' => '1700000000'],
        connectorId: 'archive-drop',
        sourceInstallationId: $installation->id,
    );
    $attempt = $runs->beginAttempt($run);
    $accepted = app(EnvelopeSubmitter::class)->submit(new ObservationEnvelope(
        sourceReference: $run->sourceReference,
        sourceName: 'Slack channel C1',
        resourceReference: 'slack:message/operator-1',
        observedAt: new DateTimeImmutable('2026-08-28T10:00:00+00:00'),
        payload: 'message-1',
        provenance: new Provenance(
            'archive-drop',
            '2.1.0',
            $installation->id,
            new DateTimeImmutable('2026-08-28T10:00:00+00:00'),
            $run->id,
        ),
    ));
    $reference = (string) $accepted->acceptedId();
    $runs->recordProgress($run, $attempt, [], ['accepted' => 1], [$reference]);
    app(IngestionCheckpoints::class)->commit(
        $stream,
        Capability::Backfill,
        'history',
        new CheckpointValue('slack.cursor', '1', 'accepted-page-1', CheckpointRule::Monotonic, 100),
        0,
        $run,
        [$reference],
        $attempt,
    );
    $runs->interrupt($runs->find($run->id), 'Worker stopped.');

    return [$runs->find($run->id), $stream, $attempt];
}

it('lists connector facts in text and JSON', function (): void {
    $registry = app(ConnectorRegistry::class);
    $registry->register(new DiscoveryAndDownloadConnector);
    $registry->register(new ConfiguringConnector('web-crawl'));

    app(ConnectorInstallations::class)->create(
        $registry->get('archive-drop'),
        'Archive account',
    );

    $text = callOperatorCommand('aleph:connectors');
    $json = callJsonOperatorCommand('aleph:connectors');
    $archive = collect($json)->firstWhere('id', 'archive-drop');
    $web = collect($json)->firstWhere('id', 'web-crawl');

    expect($text)->toContain('archive-drop')
        ->toContain('Archive Drop')
        ->toContain('sources.discover')
        ->toContain('artifacts.download')
        ->toContain('web-crawl')
        ->toContain('sources.configure')
        ->and($archive)->toBeArray()
        ->and($archive['enabled'])->toBeTrue()
        ->and($archive['capabilities'])->toContain('sources.discover', 'artifacts.download')
        ->and($archive['configuration'])->not->toBeEmpty()
        ->and($web)->toBeArray()
        ->and($web['enabled'])->toBeTrue()
        ->and($web['configuration'])->not->toBeEmpty();
});

it('configures a source from command values and returns the same facts as JSON', function (): void {
    app(ConnectorRegistry::class)->register(new ConfiguringConnector('web-crawl'));

    $parameters = [
        'connector' => 'web-crawl',
        'source-key' => 'district',
        'name' => 'District website',
        '--value' => [
            'seeds=["https://district.test/"]',
            'allowed_hosts=["district.test"]',
            'max_pages=12',
        ],
    ];

    $text = callOperatorCommand('aleph:source:configure', $parameters);
    $json = callJsonOperatorCommand('aleph:source:configure', [
        ...$parameters,
        'source-key' => 'district-json',
        'name' => 'District website JSON',
    ]);

    expect($text)->toContain('web:district')
        ->toContain('web-crawl')
        ->and($json['source_reference'])->toBe('web:district-json')
        ->and($json['configuration']['values']['seeds'])->toBe(['https://district.test/'])
        ->and($json['configuration']['values']['limits'])->toBe(['max_pages' => 12, 'max_depth' => 2])
        ->and($json['installation']['connector'])->toBe('web-crawl')
        ->and($json['installation']['enabled'])->toBeTrue()
        ->and(app(ConnectorInstallations::class)->find($json['installation']['id']))->not->toBeNull();
});

it('rejects inline source secrets without creating an installation', function (): void {
    app(ConnectorRegistry::class)->register(new ConfiguringConnector('web-crawl'));

    $exit = Artisan::call('aleph:source:configure', [
        'connector' => 'web-crawl',
        'source-key' => 'unsafe',
        'name' => 'Unsafe source',
        '--value' => [
            'seeds=["https://unsafe.test/"]',
            'allowed_hosts=["unsafe.test"]',
            'api_token=xoxb-real-secret',
        ],
        '--credential-reference' => 'vault://web/unsafe',
    ]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('api_token')
        ->and(DB::table('aleph_connector_installations')->count())->toBe(0);
});

it('lists runs in text and JSON and shows attempts and accepted references', function (): void {
    $runs = app(IngestionRuns::class);
    $older = $runs->start('web:first', Capability::WebCrawl, ['max_pages' => 1], 'web-crawl');
    $run = $runs->start('web:second', Capability::WebCrawl, ['max_pages' => 2], 'web-crawl');
    $attempt = $runs->beginAttempt($run);
    $runs->recordProgress($run, $attempt, ['next_page' => 2], ['fetched' => 1], ['observation:first']);
    $runs->succeedAttempt($run, $attempt, ['fetched' => 2], ['observation:second']);

    $listText = callOperatorCommand('aleph:runs');
    $listJson = callJsonOperatorCommand('aleph:runs');
    $shownText = callOperatorCommand('aleph:run:show', ['run' => $run->id]);
    $shownJson = callJsonOperatorCommand('aleph:run:show', ['run' => $run->id]);

    expect($listText)->toContain($older->id)
        ->toContain($run->id)
        ->toContain('web:second')
        ->and(collect($listJson)->pluck('id')->all())->toContain($older->id, $run->id)
        ->and($shownText)->toContain($run->id)
        ->toContain('completed')
        ->toContain('observation:first')
        ->toContain('observation:second')
        ->and($shownJson['id'])->toBe($run->id)
        ->and($shownJson['checkpoint'])->toBe(['next_page' => 2])
        ->and($shownJson['accepted_references'])->toBe(['observation:first', 'observation:second'])
        ->and($shownJson['attempts'])->toHaveCount(1)
        ->and($shownJson['attempts'][0]['status'])->toBe('completed');
});

it('rejects unknown connector and run IDs', function (): void {
    $configureExit = Artisan::call('aleph:source:configure', [
        'connector' => 'missing',
        'source-key' => 'source',
        'name' => 'Missing source',
    ]);
    $configureOutput = Artisan::output();
    $showExit = Artisan::call('aleph:run:show', ['run' => '01K4UNKNOWNRUN00000000000']);
    $showOutput = Artisan::output();

    expect($configureExit)->toBe(1)
        ->and($configureOutput)->toContain('missing')
        ->and($showExit)->toBe(1)
        ->and($showOutput)->toContain('01K4UNKNOWNRUN00000000000')
        ->and(DB::table('aleph_ingestion_runs')->count())->toBe(0);
});

it('launches a run once and reports an idempotent replay', function (): void {
    [$installation, $dispatcher] = operatorLaunchFixture();
    $parameters = [
        'installation' => $installation->id,
        'capability' => 'sources.discover',
        'source' => 'archive-drop:root',
        '--parameter' => ['collection=minutes'],
        '--idempotency' => 'operator-request-1',
        '--actor' => 'identity:user/mary',
        '--decision' => 'authorization:manual-ingestion/42',
    ];

    $text = callOperatorCommand('aleph:run', $parameters);
    $runId = $dispatcher->runIds[0];
    $first = app(IngestionRuns::class)->find($runId);
    $json = callJsonOperatorCommand('aleph:run', $parameters);

    expect($first)->not->toBeNull()
        ->and($text)->toContain($first->id)
        ->toContain('pending')
        ->and($json['run']['id'])->toBe($first->id)
        ->and($json['run']['parameters'])->toBe(['collection' => 'minutes'])
        ->and($json['replayed'])->toBeTrue()
        ->and($dispatcher->runIds)->toBe([$first->id])
        ->and(DB::table('aleph_ingestion_runs')->count())->toBe(1);
});

it('rejects invalid run requests before creating or dispatching work', function (): void {
    [$installation, $dispatcher] = operatorLaunchFixture();

    $exit = Artisan::call('aleph:run', [
        'installation' => $installation->id,
        'capability' => 'history.backfill',
        'source' => 'archive-drop:root',
        '--idempotency' => 'unsupported-request',
        '--actor' => 'identity:user/mary',
        '--decision' => 'authorization:manual-ingestion/43',
    ]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('does not advertise')
        ->and($dispatcher->runIds)->toBe([])
        ->and(DB::table('aleph_ingestion_runs')->count())->toBe(0);
});

it('retries a retryable attempt once and rejects unsafe retries', function (): void {
    $runs = app(IngestionRuns::class);
    $run = $runs->start('linear:workspace/example', Capability::IncrementalSync, ['scope' => 'issues']);
    $attempt = $runs->beginAttempt($run);
    $runs->failAttempt(
        $run,
        $attempt,
        new RunFailure('rate_limited', 'Try later.', true),
        partial: true,
        remainingWork: [['partition_key' => 'team:T2']],
        partitionKey: 'team:T2',
    );
    $queue = new OperatorRecordingQueue;
    app()->instance(IngestionQueue::class, $queue);
    $parameters = [
        'run' => $run->id,
        'attempt' => $attempt->id,
        '--reason' => 'Operator retry',
        '--partition' => 'team:T2',
    ];

    $text = callOperatorCommand('aleph:run:retry', $parameters);
    $json = callJsonOperatorCommand('aleph:run:retry', $parameters);
    $missingReason = Artisan::call('aleph:run:retry', [
        'run' => $run->id,
        'attempt' => $attempt->id,
    ]);

    expect($text)->toContain($run->id)
        ->toContain('Attempt')
        ->and($json['attempt']['retry_of_id'])->toBe($attempt->id)
        ->and($json['attempt']['partition_key'])->toBe('team:T2')
        ->and($json['replayed'])->toBeTrue()
        ->and($queue->attemptIds)->toHaveCount(1)
        ->and($missingReason)->toBe(1)
        ->and(Artisan::output())->toContain('operator reason');
});

it('resumes accepted checkpoint work and rejects an unsafe resume', function (): void {
    [$run, $stream] = operatorResumeFixture();
    $queue = new OperatorRecordingQueue;
    app()->instance(IngestionQueue::class, $queue);
    $parameters = [
        'run' => $run->id,
        'stream' => $stream->id,
        '--partition' => ['history'],
        '--lease-owner' => 'worker:operator-1',
        '--lease-seconds' => '60',
        '--max-partitions' => '1',
        '--max-records' => '250',
        '--max-runtime' => '30',
    ];

    $text = callOperatorCommand('aleph:run:resume', $parameters);
    $json = callJsonOperatorCommand('aleph:run:resume', $parameters);
    $missingOwner = Artisan::call('aleph:run:resume', [
        'run' => $run->id,
        'stream' => $stream->id,
        '--partition' => ['history'],
    ]);

    expect($text)->toContain($run->id)
        ->toContain('Continuations')
        ->and($json['attempt']['id'])->not->toBeEmpty()
        ->and($json['continuations'][0]['value'])->toBe('accepted-page-1')
        ->and($json['budget'])->toBe([
            'max_partitions' => 1,
            'max_records' => 250,
            'max_runtime_seconds' => 30,
        ])
        ->and($queue->attemptIds)->toHaveCount(1)
        ->and($missingOwner)->toBe(1)
        ->and(Artisan::output())->toContain('lease owner');
});
