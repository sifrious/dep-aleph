<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GitHub;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class GitHubWebhookNormalizer
{
    /**
     * @return list<GitHubActivity>
     */
    public function normalize(string $event, string $body): array
    {
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($payload)) {
            throw new InvalidArgumentException('GitHub webhook payload must be a JSON object.');
        }

        $repository = $payload['repository']['full_name'] ?? null;
        [$kind, $resource] = match ($event) {
            'repository' => [GitHubActivityKind::Repository, $payload['repository'] ?? null],
            'pull_request' => [GitHubActivityKind::PullRequest, $payload['pull_request'] ?? null],
            'pull_request_review' => [GitHubActivityKind::Review, $payload['review'] ?? null],
            'issue_comment', 'pull_request_review_comment' => [GitHubActivityKind::Comment, $payload['comment'] ?? null],
            'reaction' => [GitHubActivityKind::Reaction, $payload['reaction'] ?? null],
            'member' => [GitHubActivityKind::Contributor, $payload['member'] ?? null],
            default => throw new InvalidArgumentException("GitHub webhook event [{$event}] is not supported."),
        };

        if (! is_string($repository) || ! is_array($resource)) {
            throw new InvalidArgumentException('GitHub webhook payload lacks repository or activity data.');
        }

        $nodeId = $resource['node_id'] ?? $resource['id'] ?? null;
        $updatedAt = $resource['updated_at'] ?? $resource['created_at'] ?? $payload['repository']['updated_at'] ?? null;

        if ((! is_string($nodeId) && ! is_int($nodeId)) || ! is_string($updatedAt)) {
            throw new InvalidArgumentException('GitHub webhook activity lacks a stable node ID or update time.');
        }

        return [new GitHubActivity(
            $kind,
            $repository,
            (string) $nodeId,
            new DateTimeImmutable($updatedAt),
            $this->canonicalPayload($event, $payload, $resource),
        )];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $resource
     * @return array<string, mixed>
     */
    private function canonicalPayload(string $event, array $payload, array $resource): array
    {
        return [
            'action' => $payload['action'] ?? null,
            'event' => $event,
            'repository' => $payload['repository']['full_name'],
            'resource' => $resource,
            'sender' => $payload['sender'] ?? null,
        ];
    }
}
