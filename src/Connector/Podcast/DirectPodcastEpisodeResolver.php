<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Podcast;

final readonly class DirectPodcastEpisodeResolver implements PodcastEpisodeResolver
{
    public function resolve(string $reference): PodcastEpisodeResolution
    {
        $reference = trim($reference);

        if (! str_starts_with($reference, 'enclosure:')) {
            throw new UnsupportedPodcastReference('Direct podcast references must start with enclosure:.');
        }

        $url = trim(substr($reference, strlen('enclosure:')));
        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? trim((string) ($parts['host'] ?? '')) : '';

        if (! is_array($parts) || ! in_array($scheme, ['http', 'https'], true) || $host === ''
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsupportedPodcastReference('Direct podcast enclosures require an http or https URL without embedded credentials.');
        }

        return new PodcastEpisodeResolution(
            'podcast:enclosure/'.hash('sha256', $url),
            $url,
            ['input' => 'direct_enclosure'],
        );
    }
}
