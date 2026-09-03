<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Podcast;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Throwable;

final readonly class HttpPodcastEnclosureDownloader implements PodcastEnclosureDownloader
{
    public function __construct(private Factory $http) {}

    public function download(string $enclosureUrl): PodcastEnclosureDownload
    {
        $this->assertHttpUrl($enclosureUrl);

        try {
            $response = $this->http
                ->withHeaders([
                    'User-Agent' => 'AlephPodcastIngestion/1.0',
                ])
                ->timeout(20)
                ->connectTimeout(5)
                ->get($enclosureUrl);
        } catch (ConnectionException $failure) {
            throw new RetryablePodcastEnclosureDownloadFailure($failure->getMessage(), previous: $failure);
        } catch (Throwable $failure) {
            throw new UnfetchablePodcastEpisode($failure->getMessage(), previous: $failure);
        }

        if ($response->status() === 429 || $response->status() >= 500) {
            throw new RetryablePodcastEnclosureDownloadFailure(
                sprintf('Podcast enclosure download failed with HTTP %d.', $response->status()),
            );
        }

        if ($response->failed()) {
            throw new UnfetchablePodcastEpisode(
                sprintf('Podcast enclosure download failed with HTTP %d.', $response->status()),
            );
        }

        $body = $response->body();

        if ($body === '') {
            throw new UnfetchablePodcastEpisode('Podcast enclosure download returned an empty body.');
        }

        return new PodcastEnclosureDownload(
            mediaType: $this->mediaType($response->header('Content-Type')),
            contents: $body,
            metadata: [
                'http_status' => $response->status(),
                'content_length' => strlen($body),
                'content_type' => $response->header('Content-Type'),
            ],
        );
    }

    private function assertHttpUrl(string $url): void
    {
        $trimmed = trim($url);

        if ($trimmed === '') {
            throw new UnfetchablePodcastEpisode('A podcast enclosure URL is required.');
        }

        $parts = parse_url($trimmed);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (! is_array($parts) || ! in_array($scheme, ['http', 'https'], true)) {
            throw new UnfetchablePodcastEpisode('Podcast enclosures must use http or https URLs.');
        }
    }

    private function mediaType(mixed $contentType): string
    {
        if (! is_string($contentType) || trim($contentType) === '') {
            return 'application/octet-stream';
        }

        return strtolower(trim(explode(';', $contentType)[0]));
    }
}
