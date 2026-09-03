<?php

declare(strict_types=1);

use Sifrious\Aleph\Connector\PublicationAnalytics\A44PublicationAnalyticsFixtures;

it('defines four provider-neutral A44 fixtures without replacing source metric names', function (): void {
    $fixtures = A44PublicationAnalyticsFixtures::all();
    $serialized = json_encode(array_map(
        static fn ($fixture): array => $fixture->toArray(),
        $fixtures,
    ), JSON_THROW_ON_ERROR);

    expect($fixtures)->toHaveCount(4)
        ->and($serialized)->toContain('"contract":"A44"')
        ->and($serialized)->toContain('"source_metric_key":"impression_count"')
        ->and($serialized)->toContain('"source_metric_key":"video_view_count"')
        ->and($serialized)->toContain('"source_metric_key":"views"')
        ->and($serialized)->toContain('"source_metric_key":"sessionConversionRate"')
        ->and($serialized)->not->toContain('"source_metric_key":"impressions"');
});

it('keeps missing or unavailable distinct from zero in A44 metrics', function (): void {
    $xText = A44PublicationAnalyticsFixtures::xTextPublication()->toArray();
    $youTube = A44PublicationAnalyticsFixtures::youtubeShortPublication()->toArray();
    $bookmark = collect($xText['metrics'])->firstWhere('source_metric_key', 'bookmark_count');
    $averageViewDuration = collect($youTube['metrics'])->firstWhere('source_metric_key', 'averageViewDuration');

    expect($bookmark['availability'])->toBe('missing')
        ->and($bookmark['value'])->toBeNull()
        ->and($averageViewDuration['availability'])->toBe('unavailable')
        ->and($averageViewDuration['value'])->toBeNull();
});

it('tracks derived metrics only from compatible source metrics in each fixture', function (): void {
    $xText = A44PublicationAnalyticsFixtures::xTextPublication()->toArray();
    $shortVideo = A44PublicationAnalyticsFixtures::shortVideoPublication()->toArray();
    $web = A44PublicationAnalyticsFixtures::webConversionSource()->toArray();
    $xEngagementRate = collect($xText['metrics'])->firstWhere('source_metric_key', 'engagement_rate');
    $videoCompletionRate = collect($shortVideo['metrics'])->firstWhere('source_metric_key', 'video_completion_rate');
    $conversionRate = collect($web['metrics'])->firstWhere('source_metric_key', 'sessionConversionRate');

    expect($xEngagementRate['derived_from_source_metrics'])->toBe(['engagements', 'impression_count'])
        ->and($videoCompletionRate['derived_from_source_metrics'])->toBe(['video_complete_views', 'video_view_count'])
        ->and($conversionRate['derived_from_source_metrics'])->toBe(['keyEvents', 'sessions']);
});
