<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\YouTube;

use InvalidArgumentException;

final readonly class YouTubeCanonicalUrl
{
    private function __construct(public string $value) {}

    public static function from(string $url): self
    {
        $trimmed = trim($url);

        if ($trimmed === '') {
            throw new InvalidArgumentException('A YouTube URL is required.');
        }

        $parts = parse_url($trimmed);

        if (! is_array($parts)) {
            throw new InvalidArgumentException('The YouTube URL is not valid.');
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = trim((string) ($parts['path'] ?? ''), '/');
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);

        $videoId = self::videoId($host, $path, $query);

        if ($videoId === null) {
            throw new InvalidArgumentException('The URL must reference a single YouTube video.');
        }

        return new self('https://www.youtube.com/watch?v='.$videoId);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private static function videoId(string $host, string $path, array $query): ?string
    {
        $host = preg_replace('/^(www|m)\./', '', $host) ?? $host;

        if ($host === 'youtu.be') {
            return self::normalizedVideoId(strtok($path, '/') ?: null);
        }

        if (! str_ends_with($host, 'youtube.com')) {
            return null;
        }

        if ($path === 'watch') {
            return self::normalizedVideoId(is_string($query['v'] ?? null) ? $query['v'] : null);
        }

        $segments = $path === '' ? [] : explode('/', $path);

        if ($segments === []) {
            return null;
        }

        if (in_array($segments[0], ['shorts', 'embed', 'live'], true)) {
            return self::normalizedVideoId($segments[1] ?? null);
        }

        return null;
    }

    private static function normalizedVideoId(?string $videoId): ?string
    {
        if (! is_string($videoId)) {
            return null;
        }

        $candidate = trim($videoId);

        if ($candidate === '' || preg_match('/^[A-Za-z0-9_-]{6,15}$/', $candidate) !== 1) {
            return null;
        }

        return $candidate;
    }
}
