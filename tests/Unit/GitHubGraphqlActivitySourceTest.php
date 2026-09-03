<?php

declare(strict_types=1);

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Sifrious\Aleph\Connector\GitHub\GitHubActivityKind;
use Sifrious\Aleph\Connector\GitHub\GitHubGraphqlActivitySource;
use Sifrious\Aleph\Connector\GitHub\GitHubGraphqlTransport;
use Sifrious\Aleph\Connector\GitHub\GitHubRateLimited;
use Sifrious\Aleph\Connector\GitHub\GitHubTokenResolver;
use Sifrious\Aleph\Connector\GitHub\GitHubTokenSecret;
use Sifrious\Aleph\Connector\GitHub\HttpGitHubGraphqlTransport;

final class FixtureGitHubTokenResolver implements GitHubTokenResolver
{
    /** @var list<string> */
    public array $installations = [];

    public function resolve(string $sourceInstallationId): GitHubTokenSecret
    {
        $this->installations[] = $sourceInstallationId;

        return new GitHubTokenSecret('fixture-token');
    }
}

final class FixtureGitHubGraphqlTransport implements GitHubGraphqlTransport
{
    /** @var list<array{document: string, variables: array<string, mixed>}> */
    public array $queries = [];

    public function query(GitHubTokenSecret $token, string $document, array $variables): array
    {
        $this->queries[] = ['document' => $document, 'variables' => $variables];

        return [
            'data' => [
                'repository' => [
                    'id' => 'R_repo',
                    'nameWithOwner' => 'acme/widget',
                    'url' => 'https://github.com/acme/widget',
                    'createdAt' => '2026-01-01T00:00:00Z',
                    'updatedAt' => '2026-08-30T10:00:00Z',
                    'pullRequests' => [
                        'nodes' => [[
                            'id' => 'PR_1',
                            'number' => 1,
                            'title' => 'Ship it',
                            'createdAt' => '2026-08-29T10:00:00Z',
                            'updatedAt' => '2026-08-30T09:00:00Z',
                            'reviews' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => [[
                                'id' => 'REV_1',
                                'state' => 'APPROVED',
                                'createdAt' => '2026-08-30T08:00:00Z',
                                'updatedAt' => '2026-08-30T08:00:00Z',
                            ]]],
                            'comments' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => [[
                                'id' => 'COMMENT_1',
                                'body' => 'Ready',
                                'createdAt' => '2026-08-30T08:30:00Z',
                                'updatedAt' => '2026-08-30T08:30:00Z',
                            ]]],
                            'reactions' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => [[
                                'id' => 'REACTION_1',
                                'content' => 'THUMBS_UP',
                                'createdAt' => '2026-08-30T08:45:00Z',
                            ]]],
                        ]],
                        'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'cursor-2'],
                    ],
                ],
            ],
        ];
    }
}

it('maps a GitHub GraphQL page into canonical repository activity', function (): void {
    $tokens = new FixtureGitHubTokenResolver;
    $transport = new FixtureGitHubGraphqlTransport;
    $source = new GitHubGraphqlActivitySource('github:account/acme', 'installation-1', $tokens, $transport);

    $page = $source->page('acme/widget', 'cursor-1', 25);

    expect($source->sourceReference())->toBe('github:account/acme')
        ->and($tokens->installations)->toBe(['installation-1'])
        ->and($transport->queries[0]['variables'])->toBe([
            'owner' => 'acme',
            'name' => 'widget',
            'first' => 25,
            'after' => 'cursor-1',
        ])
        ->and($transport->queries[0]['document'])->toContain('orderBy: {field: UPDATED_AT, direction: ASC}')
        ->and($page->endCursor)->toBe('cursor-2')
        ->and($page->hasNextPage)->toBeTrue()
        ->and(array_map(static fn ($activity): GitHubActivityKind => $activity->kind, $page->activities))->toBe([
            GitHubActivityKind::Repository,
            GitHubActivityKind::PullRequest,
            GitHubActivityKind::Review,
            GitHubActivityKind::Comment,
            GitHubActivityKind::Reaction,
        ])
        ->and($page->activities[1]->resourceReference())->toBe('github:acme/widget/pull_request/PR_1');
});

it('rejects invalid repository coordinates before resolving a token', function (): void {
    $tokens = new FixtureGitHubTokenResolver;
    $source = new GitHubGraphqlActivitySource('github:account/acme', 'installation-1', $tokens, new FixtureGitHubGraphqlTransport);

    expect(fn () => $source->page('missing-owner', null, 25))
        ->toThrow(InvalidArgumentException::class, 'owner/name')
        ->and($tokens->installations)->toBe([]);
});

it('turns GitHub rate-limit responses into retry timing', function (): void {
    $reset = time() + 120;
    $handler = new MockHandler([
        new Response(403, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) $reset], '{}'),
    ]);
    $transport = new HttpGitHubGraphqlTransport(new Client(['handler' => HandlerStack::create($handler)]));

    try {
        $transport->query(new GitHubTokenSecret('fixture-token'), 'query { viewer { login } }', []);
        test()->fail('Expected the transport to report the GitHub rate limit.');
    } catch (GitHubRateLimited $failure) {
        expect($failure->retryAt)->toEqual(new DateTimeImmutable('@'.$reset));
    }
});
