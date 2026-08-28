<?php

declare(strict_types=1);

use Sifrious\Aleph\Connector\Capability;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\LaunchAuthorization;
use Sifrious\Aleph\Ingestion\LaunchIngestion;
use Sifrious\Aleph\Ingestion\LaunchIngestionRequest;
use Sifrious\Aleph\Ingestion\LaunchIngestionResult;
use Sifrious\Aleph\Ingestion\LaunchRejected;
use Sifrious\Aleph\Ingestion\ManualIngestionDispatcher;
use Sifrious\Aleph\Ingestion\RunStatus;
use Sifrious\Aleph\Testing\Fakes\DiscoveryAndDownloadConnector;

final class RecordingManualDispatcher implements ManualIngestionDispatcher
{
    /** @var list<string> */
    public array $runIds = [];

    public bool $runExistedAtDispatch = false;

    public function __construct(private readonly IngestionRuns $runs) {}

    public function dispatch(LaunchIngestionResult $launch): void
    {
        $this->runExistedAtDispatch = $this->runs->find($launch->run->id) !== null;
        $this->runIds[] = $launch->run->id;
    }
}

function manualLauncher(): array
{
    $connector = new DiscoveryAndDownloadConnector;
    $registry = app(ConnectorRegistry::class);
    $registry->register($connector);
    $installation = app(ConnectorInstallations::class)->create(
        $connector,
        'Archive account',
        owner: 'identity:user/mary',
    );
    $dispatcher = new RecordingManualDispatcher(app(IngestionRuns::class));

    return [
        new LaunchIngestion($registry, app(ConnectorInstallations::class), app(IngestionRuns::class), $dispatcher),
        $installation,
        $dispatcher,
    ];
}

function manualLaunchRequest(string $installationId, string $key = 'request-1'): LaunchIngestionRequest
{
    return new LaunchIngestionRequest(
        sourceInstallationId: $installationId,
        sourceReference: 'archive-drop:root',
        capability: Capability::DiscoversSources,
        parameters: ['collection' => 'minutes'],
        idempotencyKey: $key,
        authorization: LaunchAuthorization::granted(
            'identity:user/mary',
            'authorization:manual-ingestion/42',
        ),
    );
}

it('creates an authorized run before dispatching manual work', function (): void {
    [$launcher, $installation, $dispatcher] = manualLauncher();

    $result = $launcher->launch(manualLaunchRequest($installation->id));

    expect($result->replayed)->toBeFalse()
        ->and($result->run->status)->toBe(RunStatus::Pending)
        ->and($result->run->connectorId)->toBe('archive-drop')
        ->and($result->run->sourceInstallationId)->toBe($installation->id)
        ->and($result->run->trigger->value)->toBe('manual')
        ->and($result->run->requestedBy)->toBe('identity:user/mary')
        ->and($result->run->authorizationDecision)->toBe('authorization:manual-ingestion/42')
        ->and($result->run->requestedAt)->not->toBeNull()
        ->and($result->run->startedAt)->toBeNull()
        ->and($dispatcher->runExistedAtDispatch)->toBeTrue()
        ->and($dispatcher->runIds)->toBe([$result->run->id]);
});

it('returns the original run and does not dispatch a duplicate request', function (): void {
    [$launcher, $installation, $dispatcher] = manualLauncher();
    $request = manualLaunchRequest($installation->id);

    $first = $launcher->launch($request);
    $duplicate = $launcher->launch($request);

    expect($duplicate->replayed)->toBeTrue()
        ->and($duplicate->run->id)->toBe($first->run->id)
        ->and($duplicate->run->parameters)->toBe(['collection' => 'minutes'])
        ->and($dispatcher->runIds)->toBe([$first->run->id])
        ->and(DB::table('aleph_ingestion_runs')->count())->toBe(1);
});

it('refuses an authorization denial before creating a run', function (): void {
    [$launcher, $installation, $dispatcher] = manualLauncher();
    $request = new LaunchIngestionRequest(
        $installation->id,
        'archive-drop:root',
        Capability::DiscoversSources,
        [],
        'denied',
        LaunchAuthorization::denied(
            'identity:user/guest',
            'authorization:manual-ingestion/denied',
            'The actor cannot launch this installation.',
        ),
    );

    expect(fn () => $launcher->launch($request))
        ->toThrow(LaunchRejected::class, 'The actor cannot launch this installation.')
        ->and(DB::table('aleph_ingestion_runs')->count())->toBe(0)
        ->and($dispatcher->runIds)->toBe([]);
});

it('refuses a capability the connector does not advertise', function (): void {
    [$launcher, $installation] = manualLauncher();
    $request = new LaunchIngestionRequest(
        $installation->id,
        'archive-drop:root',
        Capability::Backfills,
        [],
        'unsupported',
        LaunchAuthorization::granted('identity:user/mary', 'authorization:manual-ingestion/43'),
    );

    expect(fn () => $launcher->launch($request))
        ->toThrow(LaunchRejected::class, 'does not advertise')
        ->and(DB::table('aleph_ingestion_runs')->count())->toBe(0);
});

it('refuses a disabled or unknown source installation', function (): void {
    [$launcher, $installation] = manualLauncher();
    app(ConnectorInstallations::class)->disable($installation->id);

    expect(fn () => $launcher->launch(manualLaunchRequest($installation->id)))
        ->toThrow(LaunchRejected::class, 'disabled')
        ->and(fn () => $launcher->launch(manualLaunchRequest('01K4UNKNOWNINSTALLATION01')))
        ->toThrow(LaunchRejected::class, 'does not exist');
});

it('refuses parameters that cannot cross an adapter boundary', function (): void {
    [$launcher, $installation] = manualLauncher();
    $request = new LaunchIngestionRequest(
        $installation->id,
        'archive-drop:root',
        Capability::DiscoversSources,
        ['stream' => fopen('php://memory', 'rb')],
        'invalid-parameters',
        LaunchAuthorization::granted('identity:user/mary', 'authorization:manual-ingestion/44'),
    );

    expect(fn () => $launcher->launch($request))
        ->toThrow(LaunchRejected::class, 'JSON serializable');
});

it('refuses an empty source reference or idempotency key', function (): void {
    [$launcher, $installation] = manualLauncher();
    $request = manualLaunchRequest($installation->id);

    expect(fn () => $launcher->launch(new LaunchIngestionRequest(
        $request->sourceInstallationId,
        '',
        $request->capability,
        $request->parameters,
        $request->idempotencyKey,
        $request->authorization,
    )))->toThrow(LaunchRejected::class, 'stable source reference')
        ->and(fn () => $launcher->launch(new LaunchIngestionRequest(
            $request->sourceInstallationId,
            $request->sourceReference,
            $request->capability,
            $request->parameters,
            '',
            $request->authorization,
        )))->toThrow(LaunchRejected::class, 'idempotency key');
});

it('gives cli desktop and phone adapters the same run shape', function (): void {
    [$launcher, $installation, $dispatcher] = manualLauncher();
    $payload = [
        'installation' => $installation->id,
        'source' => 'archive-drop:root',
        'capability' => 'sources.discover',
        'parameters' => ['collection' => 'minutes'],
        'idempotency_key' => 'cross-adapter-request',
        'actor' => 'identity:user/mary',
        'decision' => 'authorization:manual-ingestion/45',
    ];
    $adapt = static fn (array $input): LaunchIngestionRequest => new LaunchIngestionRequest(
        $input['installation'],
        $input['source'],
        Capability::from($input['capability']),
        $input['parameters'],
        $input['idempotency_key'],
        LaunchAuthorization::granted($input['actor'], $input['decision']),
    );
    $cli = $launcher->launch($adapt($payload));
    $desktop = $launcher->launch($adapt($payload));
    $phone = $launcher->launch($adapt($payload));

    expect($desktop->run->toArray())->toBe($cli->run->toArray())
        ->and($phone->run->toArray())->toBe($cli->run->toArray())
        ->and($desktop->replayed)->toBeTrue()
        ->and($phone->replayed)->toBeTrue()
        ->and($dispatcher->runIds)->toBe([$cli->run->id]);
});
