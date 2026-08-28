<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Connector\Capability as ConnectorCapability;
use Sifrious\Aleph\Connector\ConnectorDispatcher;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\GitHub\ConsumeGitHubWebhook;
use Sifrious\Aleph\Connector\GitHub\GitHubActivity;
use Sifrious\Aleph\Connector\GitHub\GitHubActivityConnector;
use Sifrious\Aleph\Connector\GitHub\GitHubActivityKind;
use Sifrious\Aleph\Connector\GitHub\GitHubActivityPage;
use Sifrious\Aleph\Connector\GitHub\GitHubActivitySource;
use Sifrious\Aleph\Connector\GitHub\GitHubActivitySources;
use Sifrious\Aleph\Connector\GitHub\GitHubRateLimited;
use Sifrious\Aleph\Connector\GitHub\GitHubWebhookDeliveries;
use Sifrious\Aleph\Connector\GitHub\GitHubWebhookSecrets;
use Sifrious\Aleph\Connector\GitHub\ImportGitHubActivities;
use Sifrious\Aleph\Connector\Values\OperationRequest;
use Sifrious\Aleph\Connector\Values\WebhookDelivery;
use Sifrious\Aleph\Ingestion\Capability as IngestionCapability;
use Sifrious\Aleph\Ingestion\IngestionCheckpoints;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\RunStatus;
use Sifrious\Aleph\Ingestion\SourceStreams;

final class PaginatedGitHubActivitySource implements GitHubActivitySource
{
    /** @var list<?string> */
    public array $requestedCursors = [];

    public function sourceReference(): string
    {
        return 'github:source:acme';
    }

    public function page(string $repository, ?string $cursor, int $limit): GitHubActivityPage
    {
        $this->requestedCursors[] = $cursor;

        if ($cursor === null) {
            return new GitHubActivityPage([
                githubActivity(GitHubActivityKind::Repository, 'R_repo', 'repository', ['node_id' => 'R_repo', 'updated_at' => '2026-08-28T10:00:00Z']),
            ], 'cursor-1', true);
        }

        return new GitHubActivityPage([
            githubPullRequestActivity(),
        ], 'cursor-2', false);
    }
}

final class RateLimitedGitHubActivitySource implements GitHubActivitySource
{
    public function sourceReference(): string
    {
        return 'github:source:limited';
    }

    public function page(string $repository, ?string $cursor, int $limit): GitHubActivityPage
    {
        throw new GitHubRateLimited(new DateTimeImmutable('2026-08-28T12:00:00Z'));
    }
}

final class EmptyGitHubActivitySource implements GitHubActivitySource
{
    public function sourceReference(): string
    {
        return 'github:source:empty';
    }

    public function page(string $repository, ?string $cursor, int $limit): GitHubActivityPage
    {
        return new GitHubActivityPage([], 'cursor-without-evidence', false);
    }
}

function githubPullRequestResource(): array
{
    return [
        'node_id' => 'PR_node_42',
        'number' => 42,
        'state' => 'open',
        'title' => 'Ship history',
        'updated_at' => '2026-08-28T11:00:00Z',
    ];
}

function githubActivity(GitHubActivityKind $kind, string $nodeId, string $event, array $resource): GitHubActivity
{
    return new GitHubActivity(
        $kind,
        'acme/widget',
        $nodeId,
        new DateTimeImmutable((string) $resource['updated_at']),
        [
            'action' => $event === 'pull_request' ? 'opened' : null,
            'event' => $event,
            'repository' => 'acme/widget',
            'resource' => $resource,
            'sender' => $event === 'pull_request' ? ['login' => 'octocat'] : null,
        ],
    );
}

function githubPullRequestActivity(): GitHubActivity
{
    return githubActivity(GitHubActivityKind::PullRequest, 'PR_node_42', 'pull_request', githubPullRequestResource());
}

function githubConnector(): GitHubActivityConnector
{
    return new GitHubActivityConnector(app(ImportGitHubActivities::class), app(ConsumeGitHubWebhook::class));
}

function githubInstallation(GitHubActivityConnector $connector, string $account = 'account:acme'): object
{
    return app(ConnectorInstallations::class)->create(
        $connector,
        $account,
        externalAccountId: $account,
        funesSourceAccountId: 'source-'.$account,
    );
}

it('paginates a backfill and converges an overlapping verified webhook on one history item', function (): void {
    $source = new PaginatedGitHubActivitySource;
    app(GitHubActivitySources::class)->register($source);
    $connector = githubConnector();
    app(ConnectorRegistry::class)->register($connector);
    $installation = githubInstallation($connector);
    $stream = app(SourceStreams::class)->create($installation->id, 'acme/widget', scopeType: 'repository', scopeId: 'github:acme/widget');
    $run = app(IngestionRuns::class)->start(
        $source->sourceReference(),
        IngestionCapability::Backfill,
        ['repository' => 'acme/widget'],
        $connector->id(),
        $installation->id,
    );
    $attempt = app(IngestionRuns::class)->beginAttempt($run);
    $poll = app(ConnectorDispatcher::class)->dispatch($connector->id(), ConnectorCapability::Backfills, new OperationRequest(
        $source->sourceReference(),
        [
            'repository' => 'acme/widget',
            'stream_id' => $stream->id,
            'run_id' => $run->id,
            'attempt_id' => $attempt->id,
            'expected_checkpoint_version' => 0,
            'page_size' => 50,
        ],
    ));
    $afterPoll = DB::table('funes_observations')->count();
    $secret = 'test-webhook-secret';
    app(GitHubWebhookSecrets::class)->register($installation->id, $secret);
    $body = json_encode([
        'action' => 'opened',
        'repository' => ['full_name' => 'acme/widget'],
        'pull_request' => githubPullRequestResource(),
        'sender' => ['login' => 'octocat'],
    ], JSON_THROW_ON_ERROR);
    $signature = 'sha256='.hash_hmac('sha256', $body, $secret);
    $delivery = new WebhookDelivery($installation->id, [
        'X-GitHub-Delivery' => 'delivery-1',
        'X-GitHub-Event' => 'pull_request',
        'X-Hub-Signature-256' => $signature,
    ], $body);
    $webhook = app(ConnectorDispatcher::class)->dispatch($connector->id(), ConnectorCapability::ConsumesWebhooks, $delivery);
    $replay = app(ConnectorDispatcher::class)->dispatch($connector->id(), ConnectorCapability::ConsumesWebhooks, $delivery);
    $checkpoint = app(IngestionCheckpoints::class)->latest($stream, IngestionCapability::Backfill, 'acme/widget');

    expect($poll->successful)->toBeTrue()
        ->and($poll->records)->toBe(2)
        ->and($poll->metadata['pages'])->toBe(2)
        ->and($source->requestedCursors)->toBe([null, 'cursor-1'])
        ->and($checkpoint?->value->value)->toBe('cursor-2')
        ->and($webhook->successful)->toBeTrue()
        ->and($replay->metadata['accepted_references'])->toBe($webhook->metadata['accepted_references'])
        ->and(DB::table('aleph_github_webhook_deliveries')->count())->toBe(1)
        ->and(DB::table('funes_observations')->count())->toBe($afterPoll);
});

it('rejects invalid signatures and isolates equal delivery ids by source account', function (): void {
    $connector = githubConnector();
    app(ConnectorRegistry::class)->register($connector);
    $first = githubInstallation($connector, 'account:first');
    $second = githubInstallation($connector, 'account:second');
    $body = json_encode([
        'action' => 'opened',
        'repository' => ['full_name' => 'acme/widget'],
        'pull_request' => githubPullRequestResource(),
        'sender' => ['login' => 'octocat'],
    ], JSON_THROW_ON_ERROR);
    app(GitHubWebhookSecrets::class)->register($first->id, 'first-secret');
    app(GitHubWebhookSecrets::class)->register($second->id, 'second-secret');
    $headers = ['X-GitHub-Delivery' => 'shared-delivery', 'X-GitHub-Event' => 'pull_request'];
    $invalid = $connector->consumeWebhook(new WebhookDelivery($first->id, $headers, $body, 'sha256=invalid'));
    $firstResult = $connector->consumeWebhook(new WebhookDelivery($first->id, $headers, $body, 'sha256='.hash_hmac('sha256', $body, 'first-secret')));
    $secondResult = $connector->consumeWebhook(new WebhookDelivery($second->id, $headers, $body, 'sha256='.hash_hmac('sha256', $body, 'second-secret')));

    expect($invalid->successful)->toBeFalse()
        ->and($invalid->error)->toContain('signature')
        ->and($firstResult->successful)->toBeTrue()
        ->and($secondResult->successful)->toBeTrue()
        ->and(DB::table('aleph_github_webhook_deliveries')->count())->toBe(2)
        ->and(app(GitHubWebhookDeliveries::class)->find($first->id, 'shared-delivery')?->sourceInstallationId)->toBe($first->id)
        ->and(app(GitHubWebhookDeliveries::class)->find($second->id, 'shared-delivery')?->sourceInstallationId)->toBe($second->id);
});

it('records provider rate limits as retryable run failures with recovery time', function (): void {
    $source = new RateLimitedGitHubActivitySource;
    app(GitHubActivitySources::class)->register($source);
    $connector = githubConnector();
    app(ConnectorRegistry::class)->register($connector);
    $installation = githubInstallation($connector, 'account:limited');
    $stream = app(SourceStreams::class)->create($installation->id, 'acme/widget');
    $run = app(IngestionRuns::class)->start(
        $source->sourceReference(),
        IngestionCapability::IncrementalSync,
        ['repository' => 'acme/widget'],
        $connector->id(),
        $installation->id,
    );
    $attempt = app(IngestionRuns::class)->beginAttempt($run);
    $result = $connector->syncIncrementally(new OperationRequest($source->sourceReference(), [
        'repository' => 'acme/widget',
        'stream_id' => $stream->id,
        'run_id' => $run->id,
        'attempt_id' => $attempt->id,
    ]));
    $failed = app(IngestionRuns::class)->find($run->id);

    expect($result->successful)->toBeFalse()
        ->and($result->metadata['retry_at'])->toBe('2026-08-28T12:00:00+00:00')
        ->and($failed?->status)->toBe(RunStatus::Failed)
        ->and($failed?->failure?->retryable)->toBeTrue()
        ->and($failed?->failure?->details['retry_at'])->toBe('2026-08-28T12:00:00+00:00')
        ->and(app(IngestionRuns::class)->attempt($attempt->id)?->backoffUntil?->format(DATE_ATOM))->toBe('2026-08-28T12:00:00+00:00');
});

it('completes an unchanged poll without advancing a cursor that lacks accepted evidence', function (): void {
    $source = new EmptyGitHubActivitySource;
    app(GitHubActivitySources::class)->register($source);
    $connector = githubConnector();
    $installation = githubInstallation($connector, 'account:empty');
    $stream = app(SourceStreams::class)->create($installation->id, 'acme/widget');
    $run = app(IngestionRuns::class)->start(
        $source->sourceReference(),
        IngestionCapability::IncrementalSync,
        ['repository' => 'acme/widget'],
        $connector->id(),
        $installation->id,
    );
    $attempt = app(IngestionRuns::class)->beginAttempt($run);
    $result = $connector->syncIncrementally(new OperationRequest($source->sourceReference(), [
        'repository' => 'acme/widget',
        'stream_id' => $stream->id,
        'run_id' => $run->id,
        'attempt_id' => $attempt->id,
    ]));

    expect($result->successful)->toBeTrue()
        ->and($result->records)->toBe(0)
        ->and(app(IngestionCheckpoints::class)->latest($stream, IngestionCapability::IncrementalSync, 'acme/widget'))->toBeNull()
        ->and(app(IngestionRuns::class)->find($run->id)?->status)->toBe(RunStatus::Completed);
});

it('advertises backfill incremental and webhook capabilities through one connector', function (): void {
    $connector = githubConnector();
    app(ConnectorRegistry::class)->register($connector);

    expect(app(ConnectorRegistry::class)->manifest($connector->id())->capabilityIds())->toBe([
        'history.backfill',
        'sync.incremental',
        'webhooks.consume',
    ]);
});
