<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GitHub;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class GitHubGraphqlActivitySource implements GitHubActivitySource
{
    public function __construct(
        private string $accountReference,
        private string $sourceInstallationId,
        private GitHubTokenResolver $tokens,
        private GitHubGraphqlTransport $transport,
    ) {
        if (trim($accountReference) === '' || trim($sourceInstallationId) === '') {
            throw new InvalidArgumentException('GitHub GraphQL source requires account and installation references.');
        }
    }

    public function sourceReference(): string
    {
        return $this->accountReference;
    }

    public function page(string $repository, ?string $cursor, int $limit): GitHubActivityPage
    {
        [$owner, $name] = $this->coordinates($repository);
        $payload = $this->transport->query($this->tokens->resolve($this->sourceInstallationId), $this->document(), [
            'owner' => $owner,
            'name' => $name,
            'first' => $limit,
            'after' => $cursor,
        ]);
        $record = $payload['data']['repository'] ?? null;

        if (! is_array($record) || ! is_array($record['pullRequests'] ?? null)) {
            throw new InvalidArgumentException('GitHub GraphQL response lacks repository pull requests.');
        }

        $connection = $record['pullRequests'];
        $nodes = $connection['nodes'] ?? null;
        $pageInfo = $connection['pageInfo'] ?? null;

        if (! is_array($nodes) || ! is_array($pageInfo)) {
            throw new InvalidArgumentException('GitHub GraphQL response lacks nodes or pageInfo.');
        }

        $activities = [$this->activity(GitHubActivityKind::Repository, $repository, $record)];

        foreach ($nodes as $pullRequest) {
            if (! is_array($pullRequest)) {
                continue;
            }

            $this->rejectTruncatedNestedConnections($pullRequest);
            $activities[] = $this->activity(GitHubActivityKind::PullRequest, $repository, $pullRequest);
            $this->append($activities, GitHubActivityKind::Review, $repository, $pullRequest['reviews']['nodes'] ?? []);
            $this->append($activities, GitHubActivityKind::Comment, $repository, $pullRequest['comments']['nodes'] ?? []);
            $this->append($activities, GitHubActivityKind::Reaction, $repository, $pullRequest['reactions']['nodes'] ?? []);
        }

        $endCursor = is_string($pageInfo['endCursor'] ?? null) ? $pageInfo['endCursor'] : null;

        return new GitHubActivityPage($activities, $endCursor, (bool) ($pageInfo['hasNextPage'] ?? false));
    }

    /** @return array{string, string} */
    private function coordinates(string $repository): array
    {
        $parts = explode('/', trim($repository));

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidArgumentException('GitHub repositories must use owner/name coordinates.');
        }

        return [$parts[0], $parts[1]];
    }

    private function document(): string
    {
        return <<<'GRAPHQL'
query AlephGitHubActivities($owner: String!, $name: String!, $first: Int!, $after: String) {
  repository(owner: $owner, name: $name) {
    id nameWithOwner url description createdAt updatedAt
    pullRequests(first: $first, after: $after, orderBy: {field: UPDATED_AT, direction: ASC}) {
      nodes {
        id number title body state url createdAt updatedAt closedAt mergedAt
        author { login }
        reviews(first: 100) { nodes { id body state url createdAt updatedAt author { login } } pageInfo { hasNextPage } }
        comments(first: 100) { nodes { id body url createdAt updatedAt author { login } } pageInfo { hasNextPage } }
        reactions(first: 100) { nodes { id content createdAt user { login } } pageInfo { hasNextPage } }
      }
      pageInfo { hasNextPage endCursor }
    }
  }
}
GRAPHQL;
    }

    /** @param array<string, mixed> $pullRequest */
    private function rejectTruncatedNestedConnections(array $pullRequest): void
    {
        foreach (['reviews', 'comments', 'reactions'] as $connectionName) {
            $connection = $pullRequest[$connectionName] ?? null;
            $pageInfo = is_array($connection) ? $connection['pageInfo'] ?? null : null;

            if (is_array($pageInfo) && ($pageInfo['hasNextPage'] ?? false) === true) {
                throw new InvalidArgumentException(
                    "GitHub pull request {$connectionName} exceed the supported 100-record page. Register a source that paginates this connection."
                );
            }
        }
    }

    /**
     * @param  list<GitHubActivity>  $activities
     */
    private function append(array &$activities, GitHubActivityKind $kind, string $repository, mixed $records): void
    {
        foreach (is_array($records) ? $records : [] as $record) {
            if (is_array($record)) {
                $activities[] = $this->activity($kind, $repository, $record);
            }
        }
    }

    /** @param array<string, mixed> $record */
    private function activity(GitHubActivityKind $kind, string $repository, array $record): GitHubActivity
    {
        $id = $record['id'] ?? null;
        $updatedAt = $record['updatedAt'] ?? $record['createdAt'] ?? null;

        if (! is_string($id) || ! is_string($updatedAt)) {
            throw new InvalidArgumentException("GitHub {$kind->value} lacks a stable node ID or timestamp.");
        }

        return new GitHubActivity($kind, $repository, $id, new DateTimeImmutable($updatedAt), $record);
    }
}
