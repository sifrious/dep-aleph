<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\PublicationAnalytics;

use DateTimeImmutable;

final class A44PublicationAnalyticsFixtures
{
    public static function xTextPublication(): A44PublicationAnalyticsObservation
    {
        return new A44PublicationAnalyticsObservation(
            provider: PublicationAnalyticsProvider::X,
            providerAccountReference: 'x:account/sifrious',
            publicationReference: 'x:post/1900111222333444555',
            publicationKind: 'text_publication',
            publicationUrl: 'https://x.com/sifrious/status/1900111222333444555',
            windowStartAt: new DateTimeImmutable('2026-08-27T00:00:00+00:00'),
            windowEndAt: new DateTimeImmutable('2026-08-28T00:00:00+00:00'),
            observedAt: new DateTimeImmutable('2026-08-28T00:00:00+00:00'),
            retrievedAt: new DateTimeImmutable('2026-08-28T00:00:03+00:00'),
            organicPaidClassification: 'organic',
            attributionScope: 'post-level analytics from the X account timeline endpoint',
            attributionLimitations: 'External shares and dark social propagation are not attributable from the provider snapshot.',
            metrics: [
                new PublicationAnalyticsMetricObservation(
                    sourceMetricKey: 'impression_count',
                    sourceMetricDefinition: 'Number of times users viewed this post.',
                    sourceMetricVersion: 'v2',
                    sourceApiVersion: '2026-08-15',
                    availability: MetricAvailability::Reported,
                    value: 1280,
                    unit: 'count',
                    normalizedMetricKey: 'reach.impressions',
                ),
                new PublicationAnalyticsMetricObservation(
                    sourceMetricKey: 'engagements',
                    sourceMetricDefinition: 'Total engagements across likes, replies, reposts, and clicks.',
                    sourceMetricVersion: 'v2',
                    sourceApiVersion: '2026-08-15',
                    availability: MetricAvailability::Reported,
                    value: 141,
                    unit: 'count',
                    normalizedMetricKey: 'engagement.total',
                ),
                new PublicationAnalyticsMetricObservation(
                    sourceMetricKey: 'engagement_rate',
                    sourceMetricDefinition: 'Engagements divided by impression count.',
                    sourceMetricVersion: 'v2',
                    sourceApiVersion: '2026-08-15',
                    availability: MetricAvailability::Reported,
                    value: 0.11015625,
                    unit: 'ratio',
                    normalizedMetricKey: 'engagement.rate',
                    derivedFromSourceMetrics: ['engagements', 'impression_count'],
                ),
                new PublicationAnalyticsMetricObservation(
                    sourceMetricKey: 'bookmark_count',
                    sourceMetricDefinition: 'Times this publication was saved to bookmarks.',
                    sourceMetricVersion: 'v2',
                    sourceApiVersion: '2026-08-15',
                    availability: MetricAvailability::Missing,
                    value: null,
                    unit: 'count',
                    normalizedMetricKey: 'engagement.bookmarks',
                ),
            ],
            rawPayloadReference: 'x:analytics/1900111222333444555?window=2026-08-27',
            normalizationVersion: 'a44.1',
            checkpoint: ['format' => 'x.analytics.cursor', 'version' => 2, 'value' => 'cursor:post-1900111222333444555:2026-08-28'],
            freshness: ['state' => 'current', 'source_revision' => 'x:sync/2026-08-28T00:00:03Z'],
        );
    }

    public static function shortVideoPublication(): A44PublicationAnalyticsObservation
    {
        return new A44PublicationAnalyticsObservation(
            provider: PublicationAnalyticsProvider::X,
            providerAccountReference: 'x:account/sifrious',
            publicationReference: 'x:post/1900444555666777888',
            publicationKind: 'short_video',
            publicationUrl: 'https://x.com/sifrious/status/1900444555666777888',
            windowStartAt: new DateTimeImmutable('2026-08-27T00:00:00+00:00'),
            windowEndAt: new DateTimeImmutable('2026-08-28T00:00:00+00:00'),
            observedAt: new DateTimeImmutable('2026-08-28T00:00:00+00:00'),
            retrievedAt: new DateTimeImmutable('2026-08-28T00:00:05+00:00'),
            organicPaidClassification: 'mixed',
            attributionScope: 'In-feed short-video analytics where paid boosts are included by X.',
            attributionLimitations: 'Unique viewers are unavailable when a provider snapshot includes only aggregate counts.',
            metrics: [
                new PublicationAnalyticsMetricObservation(
                    sourceMetricKey: 'video_view_count',
                    sourceMetricDefinition: 'Total video plays for the post.',
                    sourceMetricVersion: 'v2',
                    sourceApiVersion: '2026-08-15',
                    availability: MetricAvailability::Reported,
                    value: 830,
                    unit: 'count',
                    normalizedMetricKey: 'video.views',
                ),
                new PublicationAnalyticsMetricObservation(
                    sourceMetricKey: 'total_watch_time_seconds',
                    sourceMetricDefinition: 'Aggregate watch time across all views.',
                    sourceMetricVersion: 'v2',
                    sourceApiVersion: '2026-08-15',
                    availability: MetricAvailability::Reported,
                    value: 3071,
                    unit: 'seconds',
                    normalizedMetricKey: 'video.watch_time',
                ),
                new PublicationAnalyticsMetricObservation(
                    sourceMetricKey: 'video_complete_views',
                    sourceMetricDefinition: 'Views reaching the end of the short video.',
                    sourceMetricVersion: 'v2',
                    sourceApiVersion: '2026-08-15',
                    availability: MetricAvailability::Reported,
                    value: 280,
                    unit: 'count',
                    normalizedMetricKey: 'video.completions',
                ),
                new PublicationAnalyticsMetricObservation(
                    sourceMetricKey: 'video_completion_rate',
                    sourceMetricDefinition: 'Completed views divided by total video views.',
                    sourceMetricVersion: 'v2',
                    sourceApiVersion: '2026-08-15',
                    availability: MetricAvailability::Reported,
                    value: 0.3373493976,
                    unit: 'ratio',
                    normalizedMetricKey: 'video.completion_rate',
                    derivedFromSourceMetrics: ['video_complete_views', 'video_view_count'],
                ),
            ],
            rawPayloadReference: 'x:analytics/1900444555666777888?window=2026-08-27',
            normalizationVersion: 'a44.1',
            checkpoint: ['format' => 'x.analytics.cursor', 'version' => 2, 'value' => 'cursor:post-1900444555666777888:2026-08-28'],
            freshness: ['state' => 'current', 'source_revision' => 'x:sync/2026-08-28T00:00:05Z'],
        );
    }

    public static function youtubeShortPublication(): A44PublicationAnalyticsObservation
    {
        return new A44PublicationAnalyticsObservation(
            provider: PublicationAnalyticsProvider::YouTube,
            providerAccountReference: 'youtube:channel/UCsifrious01',
            publicationReference: 'youtube:video/q1w2e3r4t5',
            publicationKind: 'youtube_short',
            publicationUrl: 'https://www.youtube.com/shorts/q1w2e3r4t5',
            windowStartAt: new DateTimeImmutable('2026-08-27T00:00:00+00:00'),
            windowEndAt: new DateTimeImmutable('2026-08-28T00:00:00+00:00'),
            observedAt: new DateTimeImmutable('2026-08-28T00:00:00+00:00'),
            retrievedAt: new DateTimeImmutable('2026-08-28T00:00:06+00:00'),
            organicPaidClassification: null,
            attributionScope: 'YouTube Shorts analytics for one channel/video pair.',
            attributionLimitations: 'Subscriber attribution is limited to users signed in on view.',
            metrics: [
                new PublicationAnalyticsMetricObservation(
                    sourceMetricKey: 'views',
                    sourceMetricDefinition: 'Total views for the selected date range.',
                    sourceMetricVersion: 'youtube-analytics-v2',
                    sourceApiVersion: 'v2',
                    availability: MetricAvailability::Reported,
                    value: 4021,
                    unit: 'count',
                    normalizedMetricKey: 'video.views',
                ),
                new PublicationAnalyticsMetricObservation(
                    sourceMetricKey: 'estimatedMinutesWatched',
                    sourceMetricDefinition: 'Estimated watch time in minutes.',
                    sourceMetricVersion: 'youtube-analytics-v2',
                    sourceApiVersion: 'v2',
                    availability: MetricAvailability::Reported,
                    value: 855.5,
                    unit: 'minutes',
                    normalizedMetricKey: 'video.watch_time',
                ),
                new PublicationAnalyticsMetricObservation(
                    sourceMetricKey: 'averageViewDuration',
                    sourceMetricDefinition: 'Average duration watched per view in seconds.',
                    sourceMetricVersion: 'youtube-analytics-v2',
                    sourceApiVersion: 'v2',
                    availability: MetricAvailability::Unavailable,
                    value: null,
                    unit: 'seconds',
                    normalizedMetricKey: 'video.average_view_duration',
                ),
                new PublicationAnalyticsMetricObservation(
                    sourceMetricKey: 'subscribersGained',
                    sourceMetricDefinition: 'Subscribers gained that the provider attributes to the video.',
                    sourceMetricVersion: 'youtube-analytics-v2',
                    sourceApiVersion: 'v2',
                    availability: MetricAvailability::Reported,
                    value: 11,
                    unit: 'count',
                    normalizedMetricKey: 'audience.subscribers_gained',
                ),
            ],
            rawPayloadReference: 'youtube:analytics/channel/UCsifrious01/video/q1w2e3r4t5/2026-08-27',
            normalizationVersion: 'a44.1',
            checkpoint: ['format' => 'youtube.analytics.cursor', 'version' => 5, 'value' => 'window:2026-08-27'],
            freshness: ['state' => 'current', 'source_revision' => 'youtube:sync/2026-08-28T00:00:06Z'],
        );
    }

    public static function webConversionSource(): A44PublicationAnalyticsObservation
    {
        return new A44PublicationAnalyticsObservation(
            provider: PublicationAnalyticsProvider::Web,
            providerAccountReference: 'web:property/example.test',
            publicationReference: 'web:source/newsletter-august',
            publicationKind: 'web_conversion_source',
            publicationUrl: 'https://example.test/pricing?utm_source=newsletter&utm_campaign=august',
            windowStartAt: new DateTimeImmutable('2026-08-27T00:00:00+00:00'),
            windowEndAt: new DateTimeImmutable('2026-08-28T00:00:00+00:00'),
            observedAt: new DateTimeImmutable('2026-08-28T00:00:00+00:00'),
            retrievedAt: new DateTimeImmutable('2026-08-28T00:00:07+00:00'),
            organicPaidClassification: 'paid',
            attributionScope: 'Session-level campaign attribution for one source/medium pair.',
            attributionLimitations: 'Attribution follows provider lookback defaults and excludes offline conversions.',
            metrics: [
                new PublicationAnalyticsMetricObservation(
                    sourceMetricKey: 'sessions',
                    sourceMetricDefinition: 'Sessions attributed to this source and campaign.',
                    sourceMetricVersion: 'ga4-v1',
                    sourceApiVersion: 'v1beta',
                    availability: MetricAvailability::Reported,
                    value: 610,
                    unit: 'count',
                    normalizedMetricKey: 'traffic.sessions',
                ),
                new PublicationAnalyticsMetricObservation(
                    sourceMetricKey: 'keyEvents',
                    sourceMetricDefinition: 'Tracked conversion events for this source.',
                    sourceMetricVersion: 'ga4-v1',
                    sourceApiVersion: 'v1beta',
                    availability: MetricAvailability::Reported,
                    value: 63,
                    unit: 'count',
                    normalizedMetricKey: 'conversion.events',
                ),
                new PublicationAnalyticsMetricObservation(
                    sourceMetricKey: 'sessionConversionRate',
                    sourceMetricDefinition: 'Conversions divided by attributed sessions.',
                    sourceMetricVersion: 'ga4-v1',
                    sourceApiVersion: 'v1beta',
                    availability: MetricAvailability::Reported,
                    value: 0.1032786885,
                    unit: 'ratio',
                    normalizedMetricKey: 'conversion.rate',
                    derivedFromSourceMetrics: ['keyEvents', 'sessions'],
                ),
            ],
            rawPayloadReference: 'web:analytics/property/example.test/source/newsletter-august/2026-08-27',
            normalizationVersion: 'a44.1',
            checkpoint: ['format' => 'web.analytics.cursor', 'version' => 1, 'value' => 'date:2026-08-27'],
            freshness: ['state' => 'current', 'source_revision' => 'web:sync/2026-08-28T00:00:07Z'],
        );
    }

    /** @return list<A44PublicationAnalyticsObservation> */
    public static function all(): array
    {
        return [
            self::xTextPublication(),
            self::shortVideoPublication(),
            self::youtubeShortPublication(),
            self::webConversionSource(),
        ];
    }
}
