<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Sifrious\Aleph\Connector\GitHub\GitHubGraphqlActivitySource;
use Sifrious\Aleph\Connector\GitHub\GitHubTokenResolver;
use Sifrious\Aleph\Connector\GitHub\GitHubTokenSecret;
use Sifrious\Aleph\Connector\GitHub\HttpGitHubGraphqlTransport;

it('reads one live GitHub GraphQL activity page when credentials are supplied', function (): void {
    $token = getenv('ALEPH_SMOKE_GITHUB_TOKEN');
    $repository = getenv('ALEPH_SMOKE_GITHUB_REPOSITORY');

    if (! is_string($token) || $token === '' || ! is_string($repository) || $repository === '') {
        test()->markTestSkipped('Set ALEPH_SMOKE_GITHUB_TOKEN and ALEPH_SMOKE_GITHUB_REPOSITORY to run the live GitHub check.');
    }

    $tokens = new class($token) implements GitHubTokenResolver
    {
        public function __construct(private readonly string $token) {}

        public function resolve(string $sourceInstallationId): GitHubTokenSecret
        {
            return new GitHubTokenSecret($this->token);
        }
    };
    $source = new GitHubGraphqlActivitySource(
        'github:smoke',
        'smoke-installation',
        $tokens,
        new HttpGitHubGraphqlTransport(new Client),
    );

    $page = $source->page($repository, null, 1);

    expect($page->activities)->not->toBeEmpty()
        ->and($page->activities[0]->repository)->toBe($repository);
})->group('smoke');
