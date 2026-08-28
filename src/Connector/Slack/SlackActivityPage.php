<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

use InvalidArgumentException;

final readonly class SlackActivityPage
{
    /** @param list<SlackActivity> $activities */
    public function __construct(
        public array $activities,
        public ?string $nextCursor,
        public ?string $highWater,
        public bool $hasMore,
    ) {
        if ($hasMore && ($nextCursor === null || $nextCursor === '')) {
            throw new InvalidArgumentException('A continuing Slack page requires a cursor.');
        }
    }
}
