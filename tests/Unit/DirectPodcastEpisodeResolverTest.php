<?php

declare(strict_types=1);

use Sifrious\Aleph\Connector\Podcast\DirectPodcastEpisodeResolver;
use Sifrious\Aleph\Connector\Podcast\UnsupportedPodcastReference;

it('resolves an explicit podcast enclosure URL to a stable episode identity', function (): void {
    $url = 'https://cdn.example.test/episodes/42.mp3?edition=full';
    $resolved = (new DirectPodcastEpisodeResolver)->resolve('enclosure:'.$url);

    expect($resolved->episodeIdentity)->toBe('podcast:enclosure/'.hash('sha256', $url))
        ->and($resolved->enclosureUrl)->toBe($url)
        ->and($resolved->metadata)->toBe(['input' => 'direct_enclosure']);
});

it('refuses ambiguous pages unsafe schemes and embedded credentials', function (string $reference): void {
    expect(fn () => (new DirectPodcastEpisodeResolver)->resolve($reference))
        ->toThrow(UnsupportedPodcastReference::class);
})->with([
    'ordinary page' => 'https://podcasts.example.test/show/42',
    'local file' => 'enclosure:file:///tmp/episode.mp3',
    'embedded credentials' => 'enclosure:https://user:secret@example.test/episode.mp3',
]);
