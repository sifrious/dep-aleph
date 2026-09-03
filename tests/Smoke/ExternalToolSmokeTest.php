<?php

declare(strict_types=1);

it('can invoke yt-dlp when external-tool smoke tests are enabled', function (): void {
    if (getenv('ALEPH_SMOKE_EXTERNAL_TOOLS') !== '1') {
        $this->markTestSkipped('Set ALEPH_SMOKE_EXTERNAL_TOOLS=1 to check optional host binaries.');
    }

    $process = proc_open(
        ['yt-dlp', '--version'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    if (! is_resource($process)) {
        throw new RuntimeException('yt-dlp is not available on this host.');
    }

    $version = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    expect($status)->toBe(0, is_string($error) ? trim($error) : '')
        ->and(trim(is_string($version) ? $version : ''))->not->toBe('');
});
