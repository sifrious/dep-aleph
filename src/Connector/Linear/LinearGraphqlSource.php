<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Linear;

use InvalidArgumentException;

final readonly class LinearGraphqlSource implements LinearActivitySource
{
    public function __construct(
        private string $workspaceReference,
        private LinearGraphqlTransport $transport,
        private LinearActivityNormalizer $normalizer = new LinearActivityNormalizer,
    ) {
        if (trim($workspaceReference) === '') {
            throw new InvalidArgumentException('Linear GraphQL source requires a workspace reference.');
        }
    }

    public function sourceReference(): string
    {
        return $this->workspaceReference;
    }

    public function page(LinearStream $stream, ?string $cursor, int $limit): LinearActivityPage
    {
        $response = $this->transport->query($this->document($stream), [
            'after' => $cursor,
            'first' => $limit,
        ]);
        $connection = $response['data'][$this->providerField($stream)] ?? null;

        if (! is_array($connection) || ! is_array($connection['nodes'] ?? null) || ! is_array($connection['pageInfo'] ?? null)) {
            throw new InvalidArgumentException("Linear {$stream->value} response lacks nodes or pageInfo.");
        }

        $activities = [];

        foreach ($connection['nodes'] as $node) {
            if (is_array($node)) {
                $activities[] = $this->normalizer->normalize($this->kind($stream), $this->workspaceReference, $node);
            }
        }

        $pageInfo = $connection['pageInfo'];
        $endCursor = is_string($pageInfo['endCursor'] ?? null) ? $pageInfo['endCursor'] : null;

        return new LinearActivityPage($activities, $endCursor, (bool) ($pageInfo['hasNextPage'] ?? false));
    }

    private function kind(LinearStream $stream): LinearActivityKind
    {
        return LinearActivityKind::from(rtrim($stream->value, 's'));
    }

    private function document(LinearStream $stream): string
    {
        $field = $this->providerField($stream);

        return 'query Aleph'.ucfirst($stream->value).'($first: Int!, $after: String) {'
            .$field.'(first: $first, after: $after) {'
            .'nodes { '.$this->fields($stream).' }'
            .'pageInfo { hasNextPage endCursor }'
            .'}}';
    }

    private function fields(LinearStream $stream): string
    {
        return match ($stream) {
            LinearStream::Projects => 'id name url description state color icon health progress startDate targetDate createdAt updatedAt archivedAt completedAt canceledAt sortOrder teams(first: 5) { nodes { id key name } }',
            LinearStream::Issues => 'id identifier number title description url branchName priority priorityLabel estimate sortOrder boardOrder subIssueSortOrder trashed customerTicketCount previousIdentifiers dueDate startedAt triagedAt completedAt canceledAt autoClosedAt autoArchivedAt snoozedUntilAt archivedAt createdAt updatedAt state { id name type color } team { id key name } project { id } projectMilestone { id } cycle { id name number } parent { id } creator { id name email } assignee { id name email } snoozedBy { id } labels(first: 50) { nodes { id name color } } attachments(first: 25) { nodes { id title subtitle url sourceType metadata } }',
            LinearStream::Milestones => 'id name description targetDate sortOrder createdAt updatedAt project { id }',
            LinearStream::Updates => 'id body health url createdAt updatedAt user { id name } project { id }',
            LinearStream::Reports, LinearStream::Tasks, LinearStream::Links => 'id url createdAt updatedAt',
        };
    }

    private function providerField(LinearStream $stream): string
    {
        return match ($stream) {
            LinearStream::Milestones => 'projectMilestones',
            LinearStream::Updates => 'projectUpdates',
            default => $stream->value,
        };
    }
}
