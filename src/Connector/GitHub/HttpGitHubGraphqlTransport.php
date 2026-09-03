<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GitHub;

use DateTimeImmutable;
use GuzzleHttp\ClientInterface;
use RuntimeException;

final readonly class HttpGitHubGraphqlTransport implements GitHubGraphqlTransport
{
    public function __construct(private ClientInterface $http) {}

    public function query(GitHubTokenSecret $token, string $document, array $variables): array
    {
        $response = $this->http->request('POST', 'https://api.github.com/graphql', [
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'Authorization' => 'Bearer '.$token->reveal(),
                'User-Agent' => 'sifrious-aleph',
                'X-GitHub-Api-Version' => '2026-03-10',
            ],
            'json' => ['query' => $document, 'variables' => $variables],
            'http_errors' => false,
            'timeout' => 30,
        ]);
        $status = $response->getStatusCode();

        if ($status === 429 || ($status === 403 && $response->getHeaderLine('X-RateLimit-Remaining') === '0')) {
            $reset = max(time() + 1, (int) $response->getHeaderLine('X-RateLimit-Reset'));

            throw new GitHubRateLimited(new DateTimeImmutable('@'.$reset));
        }

        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        if ($status < 200 || $status >= 300 || ! is_array($payload)) {
            throw new RuntimeException("GitHub GraphQL request failed with HTTP status [{$status}].");
        }

        if (is_array($payload['errors'] ?? null) && $payload['errors'] !== []) {
            $message = $payload['errors'][0]['message'] ?? 'unknown GraphQL error';
            throw new RuntimeException('GitHub GraphQL request failed: '.(is_string($message) ? $message : 'unknown GraphQL error').'.');
        }

        return $payload;
    }
}
