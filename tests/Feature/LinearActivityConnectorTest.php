<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Connector\Configuration\ConfigureSource;
use Sifrious\Aleph\Connector\Configuration\SourceConfigurationRequest;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\Linear\ConsumeLinearWebhook;
use Sifrious\Aleph\Connector\Linear\ImportLinearActivities;
use Sifrious\Aleph\Connector\Linear\LinearActivity;
use Sifrious\Aleph\Connector\Linear\LinearActivityConnector;
use Sifrious\Aleph\Connector\Linear\LinearActivityKind;
use Sifrious\Aleph\Connector\Linear\LinearActivityPage;
use Sifrious\Aleph\Connector\Linear\LinearActivitySource;
use Sifrious\Aleph\Connector\Linear\LinearActivitySources;
use Sifrious\Aleph\Connector\Linear\LinearAttachmentReference;
use Sifrious\Aleph\Connector\Linear\LinearGraphqlSource;
use Sifrious\Aleph\Connector\Linear\LinearGraphqlTransport;
use Sifrious\Aleph\Connector\Linear\LinearStream;
use Sifrious\Aleph\Connector\Linear\LinearWebhookSecrets;
use Sifrious\Aleph\Connector\Values\OperationRequest;
use Sifrious\Aleph\Connector\Values\WebhookDelivery;
use Sifrious\Aleph\Envelope\ObservationMetadata;
use Sifrious\Aleph\Ingestion\Capability;
use Sifrious\Aleph\Ingestion\IngestionCheckpoints;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\SourceStreams;
use Sifrious\Funes\Persistence\ObservationStore;

final class FixtureLinearActivitySource implements LinearActivitySource
{
    /** @var list<array{0:string, 1:?string}> */
    public array $requests = [];

    /** @param array<string, array<string, LinearActivityPage>> $pages */
    public function __construct(
        private readonly string $reference,
        private readonly array $pages,
    ) {}

    public function sourceReference(): string
    {
        return $this->reference;
    }

    public function page(LinearStream $stream, ?string $cursor, int $limit): LinearActivityPage
    {
        $this->requests[] = [$stream->value, $cursor];

        return $this->pages[$stream->value][$cursor ?? 'start']
            ?? new LinearActivityPage([], $cursor, false);
    }
}

final class FixtureLinearTransport implements LinearGraphqlTransport
{
    /** @var list<array<string, mixed>> */
    public array $variables = [];

    /** @param array<string, mixed> $response */
    public function __construct(private readonly array $response) {}

    public function query(string $document, array $variables): array
    {
        $this->variables[] = $variables;

        return $this->response;
    }
}

function linearActivity(
    string $workspace,
    string $id = 'issue-1',
    string $updatedAt = '2026-08-28T10:00:00Z',
    array $attachments = [],
): LinearActivity {
    return new LinearActivity(
        LinearActivityKind::Issue,
        $workspace,
        $id,
        new DateTimeImmutable($updatedAt),
        [
            'id' => $id,
            'identifier' => 'ENG-1',
            'title' => 'Ship Linear ingestion',
            'url' => 'https://linear.app/acme/issue/ENG-1',
            'updatedAt' => $updatedAt,
            'project' => ['id' => 'project-1'],
            'attachments' => ['nodes' => array_map(
                static fn (LinearAttachmentReference $attachment): array => [
                    'id' => $attachment->providerId,
                    'url' => $attachment->url,
                    'title' => $attachment->title,
                    'sourceType' => $attachment->sourceType,
                ],
                $attachments,
            )],
        ],
        $attachments,
    );
}

function linearConnector(): LinearActivityConnector
{
    return new LinearActivityConnector(app(ImportLinearActivities::class), app(ConsumeLinearWebhook::class));
}

function linearInstallation(LinearActivityConnector $connector, string $workspace): object
{
    return app(ConnectorInstallations::class)->create(
        $connector,
        $workspace,
        externalAccountId: $workspace,
        funesSourceAccountId: 'source-account:'.$workspace,
    );
}

function linearOperation(
    LinearActivityConnector $connector,
    object $installation,
    string $workspace,
    array $streams,
    array $versions = [],
): array {
    $stream = app(SourceStreams::class)->create($installation->id, $workspace);
    $run = app(IngestionRuns::class)->start(
        $workspace,
        Capability::IncrementalSync,
        ['streams' => $streams],
        $connector->id(),
        $installation->id,
    );
    $attempt = app(IngestionRuns::class)->beginAttempt($run);
    $request = new OperationRequest($workspace, [
        'stream_id' => $stream->id,
        'run_id' => $run->id,
        'attempt_id' => $attempt->id,
        'streams' => $streams,
        'expected_checkpoint_versions' => $versions,
        'page_size' => 50,
    ]);

    return [$connector->syncIncrementally($request), $stream, $run, $attempt];
}

it('isolates equal Linear identities across two independently configured workspaces', function (): void {
    $connector = linearConnector();

    foreach (['linear:workspace/acme', 'linear:workspace/other'] as $workspace) {
        app(LinearActivitySources::class)->register(new FixtureLinearActivitySource($workspace, [
            'issues' => ['start' => new LinearActivityPage([linearActivity($workspace)], 'cursor-1', false)],
        ]));
        linearOperation($connector, linearInstallation($connector, $workspace), $workspace, ['issues']);
    }

    expect(DB::table('funes_observations')->count())->toBe(2)
        ->and(DB::table('aleph_connector_installations')->count())->toBe(2)
        ->and(DB::table('funes_sources')->pluck('reference')->all())->toContain(
            'linear:workspace/acme',
            'linear:workspace/other',
        );
});

it('paginates projects and issues independently and restores each stream checkpoint', function (): void {
    $workspace = 'linear:workspace/acme';
    $source = new FixtureLinearActivitySource($workspace, [
        'projects' => [
            'start' => new LinearActivityPage([
                new LinearActivity(LinearActivityKind::Project, $workspace, 'project-1', new DateTimeImmutable('2026-08-28T09:00:00Z'), ['id' => 'project-1', 'updatedAt' => '2026-08-28T09:00:00Z']),
            ], 'project-cursor-1', true),
            'project-cursor-1' => new LinearActivityPage([
                new LinearActivity(LinearActivityKind::Project, $workspace, 'project-2', new DateTimeImmutable('2026-08-28T09:01:00Z'), ['id' => 'project-2', 'updatedAt' => '2026-08-28T09:01:00Z']),
            ], 'project-cursor-2', false),
        ],
        'issues' => ['start' => new LinearActivityPage([linearActivity($workspace)], 'issue-cursor-1', false)],
    ]);
    app(LinearActivitySources::class)->register($source);
    $connector = linearConnector();
    [$result, $stream] = linearOperation($connector, linearInstallation($connector, $workspace), $workspace, ['projects', 'issues']);

    expect($result->successful)->toBeTrue()
        ->and($result->records)->toBe(3)
        ->and($result->metadata['pages'])->toBe(['projects' => 2, 'issues' => 1])
        ->and($source->requests)->toBe([
            ['projects', null],
            ['projects', 'project-cursor-1'],
            ['issues', null],
        ])->and(app(IngestionCheckpoints::class)->latest($stream, Capability::IncrementalSync, 'projects')?->value->value)->toBe('project-cursor-2')
        ->and(app(IngestionCheckpoints::class)->latest($stream, Capability::IncrementalSync, 'issues')?->value->value)->toBe('issue-cursor-1');
});

it('converges poll webhook and webhook replay on one historical effect', function (): void {
    $workspace = 'linear:workspace/acme';
    $activity = linearActivity($workspace);
    app(LinearActivitySources::class)->register(new FixtureLinearActivitySource($workspace, [
        'issues' => ['start' => new LinearActivityPage([$activity], 'issue-cursor-1', false)],
    ]));
    $connector = linearConnector();
    $installation = linearInstallation($connector, $workspace);
    linearOperation($connector, $installation, $workspace, ['issues']);
    $afterPoll = DB::table('funes_observations')->count();
    $secret = 'linear-webhook-secret';
    app(LinearWebhookSecrets::class)->register($installation->id, $secret);
    $body = json_encode(['action' => 'update', 'type' => 'Issue', 'data' => $activity->payload], JSON_THROW_ON_ERROR);
    $delivery = new WebhookDelivery($installation->id, [
        'Linear-Delivery' => 'delivery-1',
        'Linear-Event' => 'Issue',
        'Linear-Signature' => hash_hmac('sha256', $body, $secret),
    ], $body);
    $webhook = $connector->consumeWebhook($delivery);
    $replay = $connector->consumeWebhook($delivery);

    expect($webhook->successful)->toBeTrue()
        ->and($replay->metadata['accepted_references'])->toBe($webhook->metadata['accepted_references'])
        ->and(DB::table('aleph_linear_webhook_deliveries')->count())->toBe(1)
        ->and(DB::table('funes_observations')->count())->toBe($afterPoll);
});

it('resumes a retried stream from its accepted checkpoint without duplicate history', function (): void {
    $workspace = 'linear:workspace/acme';
    $activity = linearActivity($workspace);
    $source = new FixtureLinearActivitySource($workspace, [
        'issues' => [
            'start' => new LinearActivityPage([$activity], 'issue-cursor-1', false),
            'issue-cursor-1' => new LinearActivityPage([$activity], 'issue-cursor-2', false),
        ],
    ]);
    app(LinearActivitySources::class)->register($source);
    $connector = linearConnector();
    $installation = linearInstallation($connector, $workspace);
    [$first, $stream] = linearOperation($connector, $installation, $workspace, ['issues']);
    $run = app(IngestionRuns::class)->start(
        $workspace,
        Capability::IncrementalSync,
        ['streams' => ['issues']],
        $connector->id(),
        $installation->id,
    );
    $attempt = app(IngestionRuns::class)->beginAttempt($run);
    $retry = $connector->syncIncrementally(new OperationRequest($workspace, [
        'stream_id' => $stream->id,
        'run_id' => $run->id,
        'attempt_id' => $attempt->id,
        'streams' => ['issues'],
        'expected_checkpoint_versions' => ['issues' => 1],
    ]));
    $checkpoint = app(IngestionCheckpoints::class)->latest($stream, Capability::IncrementalSync, 'issues');

    expect($first->successful)->toBeTrue()
        ->and($retry->successful)->toBeTrue()
        ->and($source->requests)->toBe([['issues', null], ['issues', 'issue-cursor-1']])
        ->and($checkpoint?->version)->toBe(2)
        ->and($checkpoint?->value->value)->toBe('issue-cursor-2')
        ->and(DB::table('funes_observations')->count())->toBe(1);
});

it('rejects an invalid webhook signature before durable delivery or history', function (): void {
    $workspace = 'linear:workspace/acme';
    $connector = linearConnector();
    $installation = linearInstallation($connector, $workspace);
    app(LinearWebhookSecrets::class)->register($installation->id, 'expected-secret');
    $body = json_encode(['action' => 'update', 'type' => 'Issue', 'data' => linearActivity($workspace)->payload], JSON_THROW_ON_ERROR);
    $result = $connector->consumeWebhook(new WebhookDelivery($installation->id, [
        'Linear-Delivery' => 'invalid-delivery',
        'Linear-Signature' => 'invalid',
    ], $body));

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toContain('signature')
        ->and(DB::table('aleph_linear_webhook_deliveries')->count())->toBe(0)
        ->and(DB::table('funes_observations')->count())->toBe(0);
});

it('retains provider provenance relationships and attachment references without storing artifacts', function (): void {
    $workspace = 'linear:workspace/acme';
    $attachment = new LinearAttachmentReference('attachment-1', 'https://acme.slack.com/archives/C1/p1', 'Slack thread', 'slack');
    app(LinearActivitySources::class)->register(new FixtureLinearActivitySource($workspace, [
        'issues' => ['start' => new LinearActivityPage([linearActivity($workspace, attachments: [$attachment])], 'cursor-1', false)],
    ]));
    $connector = linearConnector();
    linearOperation($connector, linearInstallation($connector, $workspace), $workspace, ['issues']);
    $payload = json_decode((string) DB::table('funes_payloads')->value('contents'), true, 512, JSON_THROW_ON_ERROR);
    $observationId = (string) DB::table('funes_observations')->value('id');
    $observation = app(ObservationStore::class)->get($observationId);
    $metadata = $observation === null ? [] : ObservationMetadata::extension($observation, 'linear.activity');

    expect($payload['provider_id'])->toBe('issue-1')
        ->and($payload['payload']['project']['id'])->toBe('project-1')
        ->and($payload['attachments'][0]['url'])->toBe('https://acme.slack.com/archives/C1/p1')
        ->and($metadata['attachment_references'][0]['provider_id'])->toBe('attachment-1')
        ->and(DB::getSchemaBuilder()->hasTable('aleph_linear_attachments'))->toBeFalse();
});

it('owns GraphQL pagination shape behind a replaceable transport', function (): void {
    $transport = new FixtureLinearTransport(['data' => ['projectUpdates' => [
        'nodes' => [[
            'id' => 'update-1',
            'updatedAt' => '2026-08-28T10:00:00Z',
            'url' => 'https://linear.app/acme/update/update-1',
            'attachments' => ['nodes' => []],
        ]],
        'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'update-cursor-1'],
    ]]]);
    $page = (new LinearGraphqlSource('linear:workspace/acme', $transport))->page(LinearStream::Updates, 'prior-cursor', 25);

    expect($page->activities[0]->kind)->toBe(LinearActivityKind::Update)
        ->and($page->endCursor)->toBe('update-cursor-1')
        ->and($page->hasNextPage)->toBeTrue()
        ->and($transport->variables)->toBe([['after' => 'prior-cursor', 'first' => 25]]);
});

it('configures the shipped Linear connector with an opaque token reference', function (): void {
    $connector = app(LinearActivityConnector::class);
    app(ConnectorRegistry::class)->register($connector);

    $configured = app(ConfigureSource::class)->configure('linear-activity', new SourceConfigurationRequest(
        sourceKey: 'acme',
        name: 'Acme Linear',
        values: ['workspace' => 'Acme', 'streams' => ['issues', 'projects', 'issues']],
        credentialReference: 'secret://linear/acme',
    ));

    expect($configured->sourceReference())->toBe('linear:acme')
        ->and($configured->installation->credentialsReference)->toBe('secret://linear/acme')
        ->and($configured->installation->configuration)->toBe([
            'workspace' => 'acme',
            'streams' => ['issues', 'projects'],
        ]);
});

it('returns identical ingestion behavior through HTTP CLI queue and scheduler-shaped invokers', function (string $invoker): void {
    $workspace = 'linear:workspace/'.$invoker;
    app(LinearActivitySources::class)->register(new FixtureLinearActivitySource($workspace, [
        'issues' => ['start' => new LinearActivityPage([linearActivity($workspace, $invoker.'-issue')], 'cursor-1', false)],
    ]));
    $connector = linearConnector();
    [$result] = linearOperation($connector, linearInstallation($connector, $workspace), $workspace, ['issues']);

    expect($result->successful)->toBeTrue()
        ->and($result->records)->toBe(1)
        ->and(array_keys($result->metadata))->toBe(['pages', 'activities', 'cursors', 'accepted_references']);
})->with(['http', 'cli', 'queue', 'scheduler']);
