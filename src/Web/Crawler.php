<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

use Sifrious\Aleph\Extraction\ExtractorSelector;
use Sifrious\Aleph\Ingestion\IngestionRun;

final readonly class Crawler
{
    public function __construct(
        private Fetcher $fetcher,
        private ExtractorSelector $extractors,
        private FunesObservationWriter $retrievals,
    ) {}

    public function crawl(WebSource $source, Frontier $frontier, IngestionRun $run): CrawlSummary
    {
        $canonicalizer = $source->canonicalizer();
        $limits = $source->limits;

        $duplicates = 0;
        $unresolvable = 0;
        $discovered = 0;

        if ($frontier->isEmpty()) {
            foreach ($source->seeds as $seed) {
                $url = $canonicalizer->canonicalize($seed);

                if ($url === null) {
                    $unresolvable++;

                    continue;
                }

                $discovered++;

                if ($this->admit($frontier, $source, $url, $seed, 0, DiscoveryOrigin::Seed, null) === null) {
                    $duplicates++;
                }
            }
        }

        $frontier->releaseClaimed();

        $fetched = $frontier->countByState(FrontierState::Fetched);
        $unsuccessful = $frontier->countUnsuccessful();
        $failed = $frontier->countByState(FrontierState::Failed);
        $stoppedBy = StopReason::FrontierExhausted;

        while (true) {
            if ($fetched >= $limits->maxPages) {
                $stoppedBy = StopReason::PageLimit;

                break;
            }

            $candidate = $frontier->claimNext();

            if ($candidate === null) {
                break;
            }

            $result = $this->fetcher->fetch(new FetchRequest($candidate->url));

            if (! $result->retrieved()) {
                $frontier->markFailed($candidate, $result);
                $failed++;

                continue;
            }

            $base = $this->baseFor($canonicalizer, $result, $candidate);
            $extraction = $this->extractors->extract($result);
            $observationDiscoveries = [];

            if ($result->isOk() && $source->hosts->allows($base->host)) {
                foreach ($extraction->discoveries as $discovery) {
                    $discovered++;
                    $url = $canonicalizer->canonicalize($discovery->reference, $base);

                    if ($url === null) {
                        $unresolvable++;

                        continue;
                    }

                    $observationDiscoveries[$discovery->relationship->value."\0".$url->value] = new CanonicalDiscovery(
                        $url,
                        $discovery->relationship,
                    );
                    $admitted = $this->admit(
                        $frontier,
                        $source,
                        $url,
                        $discovery->reference,
                        $candidate->depth + 1,
                        DiscoveryOrigin::from($discovery->relationship->value),
                        $candidate->id,
                    );

                    if ($admitted === null) {
                        $duplicates++;
                    }
                }
            }

            $accepted = $this->retrievals->accept(
                $source,
                $run,
                $candidate,
                $base,
                $result,
                $extraction,
                array_values($observationDiscoveries),
            );
            $frontier->markFetched($candidate, $result, $accepted);
            $fetched++;

            if (! $result->isOk()) {
                $unsuccessful++;
            }
        }

        return new CrawlSummary(
            runId: $run->id,
            source: $source->key,
            fetched: $fetched,
            unsuccessful: $unsuccessful,
            failed: $failed,
            skippedByReason: $frontier->skippedByReason(),
            duplicates: $duplicates,
            unresolvable: $unresolvable,
            discovered: $discovered,
            remaining: $frontier->countByState(FrontierState::Pending),
            stoppedBy: $stoppedBy,
        );
    }

    private function admit(
        Frontier $frontier,
        WebSource $source,
        CanonicalUrl $url,
        string $requestedUrl,
        int $depth,
        DiscoveryOrigin $origin,
        ?int $parentId,
    ): ?int {
        $state = FrontierState::Skipped;
        $reason = null;

        if (! $source->hosts->allows($url->host)) {
            $reason = SkipReason::ExternalHost;
        } elseif ($source->excludes($url)) {
            $reason = SkipReason::Excluded;
        } elseif ($depth > $source->limits->maxDepth) {
            $reason = SkipReason::DepthLimit;
        } else {
            $state = FrontierState::Pending;
        }

        return $frontier->record($url, $requestedUrl, $depth, $origin, $parentId, $state, $reason);
    }

    private function baseFor(
        UrlCanonicalizer $canonicalizer,
        FetchResult $result,
        FrontierCandidate $candidate,
    ): CanonicalUrl {
        if ($result->finalUrl === null) {
            return $candidate->url;
        }

        return $canonicalizer->canonicalize($result->finalUrl) ?? $candidate->url;
    }
}
