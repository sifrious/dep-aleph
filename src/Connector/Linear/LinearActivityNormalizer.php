<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Linear;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class LinearActivityNormalizer
{
    /** @param array<string, mixed> $resource */
    public function normalize(
        LinearActivityKind $kind,
        string $workspaceReference,
        array $resource,
    ): LinearActivity {
        $providerId = $resource['id'] ?? null;
        $updatedAt = $resource['updatedAt'] ?? $resource['createdAt'] ?? null;

        if (! is_string($providerId) || trim($providerId) === '' || ! is_string($updatedAt)) {
            throw new InvalidArgumentException('Linear activity lacks a stable ID or source timestamp.');
        }

        $attachments = [];
        $nodes = $resource['attachments']['nodes'] ?? $resource['attachments'] ?? [];

        if (is_array($nodes)) {
            foreach ($nodes as $attachment) {
                if (! is_array($attachment) || ! is_string($attachment['id'] ?? null) || ! is_string($attachment['url'] ?? null)) {
                    continue;
                }

                $attachments[] = new LinearAttachmentReference(
                    $attachment['id'],
                    $attachment['url'],
                    is_string($attachment['title'] ?? null) ? $attachment['title'] : null,
                    is_string($attachment['sourceType'] ?? null) ? $attachment['sourceType'] : null,
                );
            }
        }

        return new LinearActivity(
            $kind,
            $workspaceReference,
            $providerId,
            new DateTimeImmutable($updatedAt),
            $resource,
            $attachments,
        );
    }
}
