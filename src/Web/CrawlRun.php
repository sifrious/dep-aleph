<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

use DateTimeImmutable;

final readonly class CrawlRun
{
    public function __construct(
        public string $id,
        public string $source,
        public RunStatus $status,
        public CrawlLimits $limits,
        public DateTimeImmutable $startedAt,
    ) {}
}
