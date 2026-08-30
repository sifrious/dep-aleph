<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\VideoFile;

use JsonException;
use RuntimeException;
use Sifrious\Aleph\Ingestion\LaunchRejected;

/**
 * Invokes the sibling Python twin under python/video_file. No Composer require on Python.
 */
final class ProcessPythonVideoFileAdapter implements PythonVideoFileAdapter
{
    public function __construct(
        private ?string $pythonBinary = null,
        private ?string $moduleDirectory = null,
    ) {}

    public function available(): bool
    {
        return $this->resolvePython() !== null && is_file($this->ingestScript());
    }

    public function emitEnvelope(
        string $sourceReference,
        string $sourceInstallationId,
        string $runId,
        string $artifactReference,
        string $mediaType,
        string $contents,
        array $metadata,
    ): array {
        $python = $this->resolvePython();

        if ($python === null || ! is_file($this->ingestScript())) {
            throw new LaunchRejected(
                'language_unavailable',
                'Ingest language [python] is not available for capability [video-file].',
            );
        }

        $request = json_encode([
            'source_reference' => $sourceReference,
            'source_installation_id' => $sourceInstallationId,
            'run_id' => $runId,
            'artifact_reference' => $artifactReference,
            'media_type' => $mediaType,
            'contents_base64' => base64_encode($contents),
            'metadata' => $metadata,
        ], JSON_THROW_ON_ERROR);

        $command = escapeshellarg($python).' '.escapeshellarg($this->ingestScript());
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $this->moduleRoot());

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start the Python video-file twin process.');
        }

        fwrite($pipes[0], $request);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if ($exit !== 0) {
            throw new RuntimeException(
                'Python video-file twin failed: '.trim((string) $stderr.' '.(string) $stdout),
            );
        }

        try {
            $decoded = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new RuntimeException('Python video-file twin returned invalid JSON.', 0, $failure);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Python video-file twin returned a non-object envelope.');
        }

        return $decoded;
    }

    private function resolvePython(): ?string
    {
        if ($this->pythonBinary !== null) {
            return is_executable($this->pythonBinary) ? $this->pythonBinary : null;
        }

        foreach (['python3', 'python'] as $binary) {
            $resolved = $this->which($binary);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    private function which(string $binary): ?string
    {
        $path = getenv('PATH');

        if (! is_string($path) || $path === '') {
            return null;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            $candidate = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$binary;

            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function moduleRoot(): string
    {
        return $this->moduleDirectory ?? dirname(__DIR__, 3).'/python';
    }

    private function ingestScript(): string
    {
        return $this->moduleRoot().'/video_file/ingest.py';
    }
}
