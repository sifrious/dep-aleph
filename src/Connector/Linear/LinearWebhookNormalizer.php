<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Linear;

use InvalidArgumentException;

final readonly class LinearWebhookNormalizer
{
    public function __construct(private LinearActivityNormalizer $normalizer = new LinearActivityNormalizer) {}

    /** @return list<LinearActivity> */
    public function normalize(string $workspaceReference, string $body): array
    {
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($payload) || ! is_array($payload['data'] ?? null)) {
            throw new InvalidArgumentException('Linear webhook payload must contain a data object.');
        }

        $kind = match ($payload['type'] ?? null) {
            'Project' => LinearActivityKind::Project,
            'Issue' => LinearActivityKind::Issue,
            'ProjectMilestone' => LinearActivityKind::Milestone,
            'ProjectUpdate' => LinearActivityKind::Update,
            'Report' => LinearActivityKind::Report,
            'Task' => LinearActivityKind::Task,
            'Link', 'Attachment' => LinearActivityKind::Link,
            default => throw new InvalidArgumentException('Linear webhook resource type is not supported.'),
        };

        return [$this->normalizer->normalize($kind, $workspaceReference, $payload['data'])];
    }
}
