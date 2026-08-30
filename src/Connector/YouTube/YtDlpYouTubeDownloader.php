<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\YouTube;

use RuntimeException;

final class YtDlpYouTubeDownloader implements YouTubeDownloader
{
    public function download(YouTubeCanonicalUrl $url): YouTubeDownload
    {
        if (! $this->binaryExists('yt-dlp')) {
            throw new RetryableYouTubeDownloadFailure('yt-dlp is not available on this host.');
        }

        $directory = $this->temporaryDirectory();
        $template = $directory.'/video.%(ext)s';
        try {
            $json = $this->run([
                'yt-dlp',
                '--no-playlist',
                '--no-progress',
                '--dump-single-json',
                '--write-auto-subs',
                '--write-subs',
                '--sub-langs',
                'en.*,en',
                '-f',
                'bv*+ba/b',
                '-o',
                $template,
                $url->value,
            ]);
            $decoded = json_decode($json, true);

            if (! is_array($decoded)) {
                throw new RetryableYouTubeDownloadFailure('yt-dlp returned invalid metadata JSON.');
            }

            $filePath = $this->mediaPath($decoded, $directory);

            if ($filePath === null || ! is_file($filePath)) {
                throw new RetryableYouTubeDownloadFailure('yt-dlp did not produce a downloadable media artifact.');
            }

            $contents = file_get_contents($filePath);

            if (! is_string($contents)) {
                throw new RetryableYouTubeDownloadFailure('The downloaded media artifact could not be read.');
            }

            $mediaType = $this->mediaType((string) pathinfo($filePath, PATHINFO_EXTENSION));
            $transcript = $this->transcript($directory);

            return new YouTubeDownload(
                mediaType: $mediaType,
                contents: $contents,
                metadata: array_filter([
                    'id' => $decoded['id'] ?? null,
                    'title' => $decoded['title'] ?? null,
                    'duration' => $decoded['duration'] ?? null,
                    'uploader' => $decoded['uploader'] ?? null,
                    'upload_date' => $decoded['upload_date'] ?? null,
                    'webpage_url' => $decoded['webpage_url'] ?? null,
                ], static fn (mixed $value): bool => $value !== null),
                transcript: $transcript,
            );
        } finally {
            $this->removeDirectory($directory);
        }
    }

    /**
     * @param  array<int, string>  $command
     */
    private function run(array $command): string
    {
        $descriptor = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = proc_open($command, $descriptor, $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to invoke yt-dlp.');
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);

        if ($exit !== 0) {
            throw new RetryableYouTubeDownloadFailure(trim($stderr) !== '' ? trim($stderr) : 'yt-dlp failed.');
        }

        return trim($stdout);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function mediaPath(array $metadata, string $directory): ?string
    {
        $download = $metadata['requested_downloads'][0] ?? null;

        if (is_array($download) && is_string($download['filepath'] ?? null)) {
            return $download['filepath'];
        }

        $matches = glob($directory.'/video.*');

        if (! is_array($matches) || $matches === []) {
            return null;
        }

        return $matches[0];
    }

    private function transcript(string $directory): ?YouTubeTranscript
    {
        $matches = glob($directory.'/video*.vtt');

        if (! is_array($matches) || $matches === []) {
            return null;
        }

        $path = $matches[0];
        $contents = file_get_contents($path);

        if (! is_string($contents) || trim($contents) === '') {
            return null;
        }

        $language = null;
        $segments = explode('.', basename($path));

        if (count($segments) >= 3) {
            $language = $segments[count($segments) - 2];
        }

        return new YouTubeTranscript('text/vtt', $contents, $language);
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/aleph-youtube-'.bin2hex(random_bytes(6));

        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create a temporary directory for yt-dlp.');
        }

        return $directory;
    }

    private function mediaType(string $extension): string
    {
        return match (strtolower($extension)) {
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'webm' => 'video/webm',
            'mkv' => 'video/x-matroska',
            default => 'video/mp4',
        };
    }

    private function binaryExists(string $binary): bool
    {
        $path = trim((string) shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null'));

        return $path !== '';
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);

        if (! is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            @unlink($directory.'/'.$entry);
        }

        @rmdir($directory);
    }
}
