<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

enum Capability: string
{
    case WebCrawl = 'web.crawl';
    case DiscoverSources = 'sources.discover';
    case Backfill = 'history.backfill';
    case IncrementalSync = 'sync.incremental';
    case ConsumeWebhook = 'webhooks.consume';
    case DownloadArtifact = 'artifacts.download';
    case CheckHealth = 'health.check';
}
