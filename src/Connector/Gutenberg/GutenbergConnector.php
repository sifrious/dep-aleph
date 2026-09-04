<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Gutenberg;

use Closure;
use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use RuntimeException;
use Sifrious\Aleph\Connector\ConfigurationField;
use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\Connector;
use Sifrious\Aleph\Connector\Contracts\DiscoversSources;
use Sifrious\Aleph\Connector\Contracts\DownloadsArtifacts;
use Sifrious\Aleph\Connector\Values\Artifact;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;
use Sifrious\Aleph\Connector\Values\DiscoveredSource;
use Sifrious\Aleph\Connector\Values\DiscoveredSources;
use Sifrious\Aleph\Connector\Values\OperationRequest;
use Throwable;

/**
 * Acquires Project Gutenberg bytes without interpreting them as books or editions.
 *
 * The `gutenberg:ebook/{id}` reference is explicitly provisional until MME-3813
 * supplies canonical bibliographic identity value objects.
 */
final class GutenbergConnector implements Connector, DiscoversSources, DownloadsArtifacts
{
    private const SELECTION_POLICY = 'plain-text-utf8 > plain-text > epub-no-images > epub > other';

    private Closure $now;

    private Closure $sleep;

    public function __construct(
        private readonly Factory $http,
        private readonly string $cacheDirectory,
        private readonly string $baseUrl = 'https://www.gutenberg.org',
        private readonly int $maxAttempts = 3,
        private readonly int $retryDelayMilliseconds = 200,
        ?Closure $now = null,
        ?Closure $sleep = null,
    ) {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('Gutenberg max attempts must be at least one.');
        }

        $this->now = $now ?? static fn (): string => gmdate('Y-m-d\TH:i:s\Z');
        $this->sleep = $sleep ?? static fn (int $milliseconds): null => usleep($milliseconds * 1000);
    }

    public function id(): string
    {
        return 'project-gutenberg';
    }

    public function name(): string
    {
        return 'Project Gutenberg';
    }

    public function version(): string
    {
        return '0.1.0-provisional-identity';
    }

    public function configuration(): ConfigurationSchema
    {
        return new ConfigurationSchema(
            ConfigurationField::text('cache_directory', 'Directory for immutable source artifacts and acquisition manifests.'),
            ConfigurationField::text('base_url', 'Project Gutenberg origin.', required: false),
            ConfigurationField::number('max_attempts', 'Bounded HTTP attempts for retryable failures.', required: false),
        );
    }

    public function discoverSources(OperationRequest $request): DiscoveredSources
    {
        $ids = $this->requestedIds($request);
        $sources = [];

        foreach ($ids as $id) {
            $metadata = $this->metadata($id);
            $sources[] = new DiscoveredSource(
                reference: $this->sourceReference($id),
                name: $metadata['title'] !== '' ? $metadata['title'] : "Project Gutenberg ebook {$id}",
                metadata: [
                    'provider' => 'project-gutenberg',
                    'ebook_id' => $id,
                    'metadata_url' => $metadata['metadata_url'],
                    'metadata_sha256' => $metadata['metadata_sha256'],
                    'metadata_acquired_at' => $metadata['metadata_acquired_at'],
                    'creators' => $metadata['creators'],
                    'languages' => $metadata['languages'],
                    'artifact_candidates' => $metadata['files'],
                    'identity_assumption' => 'provisional: MME-3813 canonical identities not yet integrated',
                ],
            );
        }

        return new DiscoveredSources(...$sources);
    }

    public function downloadArtifact(ArtifactRequest $request): Artifact
    {
        $id = $this->idFromReference($request->sourceReference);
        $metadata = $this->metadata($id);
        $selected = $this->selectFile($metadata['files']);

        if (isset($request->parameters['artifact_url'])) {
            $requestedUrl = (string) $request->parameters['artifact_url'];
            $matches = array_values(array_filter(
                $metadata['files'],
                static fn (array $file): bool => $file['url'] === $requestedUrl,
            ));

            if ($matches === []) {
                throw new InvalidArgumentException('Requested artifact URL is not present in Gutenberg metadata.');
            }

            $selected = $matches[0];
        } elseif ($request->artifactReference !== '' && $request->artifactReference !== 'preferred') {
            $matches = array_values(array_filter(
                $metadata['files'],
                static fn (array $file): bool => $file['url'] === $request->artifactReference,
            ));

            if ($matches === []) {
                throw new InvalidArgumentException('Artifact reference must be "preferred" or a URL from Gutenberg metadata.');
            }

            $selected = $matches[0];
        }

        $cacheKey = hash('sha256', $selected['url']);
        $manifestPath = $this->cachePath("manifests/{$cacheKey}.json");
        $manifest = $this->readJson($manifestPath);
        $cacheHit = false;

        if ($manifest !== null) {
            $blobPath = $this->cachePath('blobs/'.$manifest['sha256']);

            if (is_file($blobPath)) {
                $contents = file_get_contents($blobPath);

                if (is_string($contents) && hash('sha256', $contents) === $manifest['sha256']) {
                    $cacheHit = true;
                } else {
                    $manifest = null;
                }
            } else {
                $manifest = null;
            }
        }

        if ($manifest === null) {
            $response = $this->get($selected['url']);
            $contents = $response->body();

            if ($contents === '') {
                throw new GutenbergAcquisitionFailure('Gutenberg artifact download returned an empty body.');
            }

            $sha256 = hash('sha256', $contents);
            $blobPath = $this->cachePath("blobs/{$sha256}");
            $this->writeOnce($blobPath, $contents);
            $manifest = [
                'schema' => 1,
                'source_reference' => $this->sourceReference($id),
                'artifact_url' => $selected['url'],
                'sha256' => $sha256,
                'byte_size' => strlen($contents),
                'media_type' => $this->mediaType($response, $selected['media_type']),
                'acquired_at' => ($this->now)(),
                'http' => $this->responseEvidence($response),
            ];
            $this->writeJsonAtomically($manifestPath, $manifest);
        }

        $encoding = $this->detectEncoding($contents, $selected['media_type']);

        return new Artifact(
            reference: $selected['url'],
            mediaType: $manifest['media_type'],
            contents: $contents,
            metadata: [
                'provider' => 'project-gutenberg',
                'source_reference' => $this->sourceReference($id),
                'identity_assumption' => 'provisional: replace with MME-3813 canonical resource/book-file identities',
                'ebook_id' => $id,
                'artifact_url' => $selected['url'],
                'selected_format' => $selected['media_type'],
                'selection_policy' => self::SELECTION_POLICY,
                'sha256' => $manifest['sha256'],
                'content_identity' => 'sha256:'.$manifest['sha256'],
                'byte_size' => $manifest['byte_size'],
                'encoding' => $encoding,
                'boundaries' => $this->boilerplateBoundaries($contents, $encoding),
                'original_bytes_preserved' => true,
                'interpretation_performed' => false,
                'cache_key' => $cacheKey,
                'cache_hit' => $cacheHit,
                'acquired_at' => $manifest['acquired_at'],
                'http' => $manifest['http'],
                'metadata_url' => $metadata['metadata_url'],
                'metadata_sha256' => $metadata['metadata_sha256'],
                'metadata_acquired_at' => $metadata['metadata_acquired_at'],
            ],
        );
    }

    /**
     * @return list<int>
     */
    private function requestedIds(OperationRequest $request): array
    {
        $ids = $request->parameter('ebook_ids');

        if (is_array($ids) && $ids !== []) {
            $normalized = array_map(fn (mixed $id): int => $this->positiveId($id), $ids);

            return array_values(array_unique($normalized));
        }

        return [$this->idFromReference($request->sourceReference)];
    }

    private function idFromReference(string $reference): int
    {
        if (preg_match('~^gutenberg:ebook/([1-9][0-9]*)$~', $reference, $matches) !== 1) {
            throw new InvalidArgumentException('Gutenberg sources use the provisional form gutenberg:ebook/{positive-id}.');
        }

        return (int) $matches[1];
    }

    private function positiveId(mixed $id): int
    {
        if ((! is_int($id) && ! is_string($id)) || preg_match('/^[1-9][0-9]*$/', (string) $id) !== 1) {
            throw new InvalidArgumentException('Gutenberg ebook IDs must be positive integers.');
        }

        return (int) $id;
    }

    private function sourceReference(int $id): string
    {
        return "gutenberg:ebook/{$id}";
    }

    /**
     * @return array{
     *   title: string, creators: list<string>, languages: list<string>,
     *   files: list<array{url: string, media_type: string}>,
     *   metadata_url: string, metadata_sha256: string, metadata_acquired_at: string
     * }
     */
    private function metadata(int $id): array
    {
        $recordPath = $this->cachePath("metadata/{$id}.json");
        /** @var array{
         *   title: string, creators: list<string>, languages: list<string>,
         *   files: list<array{url: string, media_type: string}>,
         *   metadata_url: string, metadata_sha256: string, metadata_acquired_at: string
         * }|null $cached
         */
        $cached = $this->readJson($recordPath);

        if ($cached !== null) {
            return $cached;
        }

        $url = $this->baseUrl."/ebooks/{$id}.rdf";
        $response = $this->get($url);
        $rdf = $response->body();

        if ($rdf === '') {
            throw new GutenbergAcquisitionFailure('Gutenberg metadata response was empty.');
        }

        $metadataSha256 = hash('sha256', $rdf);
        $this->writeOnce($this->cachePath("metadata/original/{$metadataSha256}.rdf"), $rdf);
        $parsed = $this->parseMetadata($rdf);
        $record = [
            ...$parsed,
            'metadata_url' => $url,
            'metadata_sha256' => $metadataSha256,
            'metadata_acquired_at' => ($this->now)(),
        ];
        $this->writeJsonAtomically($recordPath, $record);

        return $record;
    }

    /**
     * @return array{
     *   title: string, creators: list<string>, languages: list<string>,
     *   files: list<array{url: string, media_type: string}>
     * }
     */
    private function parseMetadata(string $rdf): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = $document->loadXML($rdf, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new GutenbergAcquisitionFailure('Gutenberg metadata was not valid RDF/XML.');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('dcterms', 'http://purl.org/dc/terms/');
        $xpath->registerNamespace('pgterms', 'http://www.gutenberg.org/2009/pgterms/');
        $xpath->registerNamespace('rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');

        $title = trim((string) $xpath->evaluate('string(//dcterms:title[1])'));
        $creators = $this->nodeValues($xpath, '//dcterms:creator//pgterms:name');
        $languages = $this->nodeValues($xpath, '//dcterms:language//rdf:value');
        $files = [];

        foreach ($xpath->query('//pgterms:file[@rdf:about]') ?: [] as $file) {
            if (! $file instanceof DOMNode) {
                continue;
            }

            $url = trim((string) $file->attributes?->getNamedItemNS(
                'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
                'about',
            )?->nodeValue);
            $mediaType = trim((string) $xpath->evaluate(
                'string(.//*[local-name()="format"]//*[local-name()="value"][1])',
                $file,
            ));

            if ($url !== '' && $mediaType !== '') {
                $files[] = ['url' => $url, 'media_type' => strtolower($mediaType)];
            }
        }

        usort($files, static fn (array $left, array $right): int => strcmp($left['url'], $right['url']));

        if ($files === []) {
            throw new GutenbergAcquisitionFailure('Gutenberg metadata listed no downloadable files.');
        }

        return compact('title', 'creators', 'languages', 'files');
    }

    /**
     * @return list<string>
     */
    private function nodeValues(DOMXPath $xpath, string $expression): array
    {
        $values = [];

        foreach ($xpath->query($expression) ?: [] as $node) {
            if (! $node instanceof DOMNode) {
                continue;
            }

            $value = trim($node->textContent);

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @param  list<array{url: string, media_type: string}>  $files
     * @return array{url: string, media_type: string}
     */
    private function selectFile(array $files): array
    {
        usort($files, function (array $left, array $right): int {
            return [$this->fileRank($left), $left['url']] <=> [$this->fileRank($right), $right['url']];
        });

        return $files[0];
    }

    /**
     * @param  array{url: string, media_type: string}  $file
     */
    private function fileRank(array $file): int
    {
        $type = strtolower($file['media_type']);
        $url = strtolower($file['url']);

        if (str_starts_with($type, 'text/plain') && str_contains($type, 'utf-8') && ! str_ends_with($url, '.zip')) {
            return 0;
        }

        if (str_starts_with($type, 'text/plain') && ! str_ends_with($url, '.zip')) {
            return 10;
        }

        if (str_contains($type, 'epub') && ! str_contains($url, 'images')) {
            return 20;
        }

        if (str_contains($type, 'epub')) {
            return 30;
        }

        return 100;
    }

    private function get(string $url): Response
    {
        $lastFailure = null;

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                $response = $this->http
                    ->withHeaders(['User-Agent' => 'AlephGutenbergAcquisition/0.1'])
                    ->connectTimeout(5)
                    ->timeout(30)
                    ->get($url);

                if ($response->successful()) {
                    return $response;
                }

                $retryable = $response->status() === 429 || $response->status() >= 500;
                $lastFailure = new GutenbergAcquisitionFailure(
                    sprintf('Gutenberg request failed with HTTP %d for %s.', $response->status(), $url),
                );

                if (! $retryable) {
                    throw $lastFailure;
                }
            } catch (ConnectionException $failure) {
                $lastFailure = new GutenbergAcquisitionFailure(
                    "Gutenberg request failed for {$url}: {$failure->getMessage()}",
                    previous: $failure,
                );
            } catch (GutenbergAcquisitionFailure $failure) {
                throw $failure;
            } catch (Throwable $failure) {
                throw new GutenbergAcquisitionFailure($failure->getMessage(), previous: $failure);
            }

            if ($attempt < $this->maxAttempts) {
                ($this->sleep)($this->retryDelayMilliseconds * $attempt);
            }
        }

        throw $lastFailure ?? new GutenbergAcquisitionFailure("Gutenberg request failed for {$url}.");
    }

    /**
     * @return array<string, int|string>
     */
    private function responseEvidence(Response $response): array
    {
        return array_filter([
            'status' => $response->status(),
            'etag' => $response->header('ETag'),
            'last_modified' => $response->header('Last-Modified'),
            'content_type' => $response->header('Content-Type'),
            'content_length' => $response->header('Content-Length'),
        ], static fn (int|string $value): bool => $value !== '');
    }

    private function mediaType(Response $response, string $declared): string
    {
        $header = $response->header('Content-Type');

        return strtolower(trim(explode(';', $header !== '' ? $header : $declared)[0]));
    }

    /**
     * @return array{detected: ?string, evidence: string}
     */
    private function detectEncoding(string $contents, string $declaredType): array
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            return ['detected' => 'UTF-8', 'evidence' => 'byte-order-mark'];
        }

        if (preg_match('/charset\s*=\s*["\']?([a-z0-9._-]+)/i', $declaredType, $matches) === 1) {
            return ['detected' => strtoupper($matches[1]), 'evidence' => 'metadata-content-type'];
        }

        $encoding = mb_detect_encoding($contents, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);

        return [
            'detected' => $encoding !== false ? $encoding : null,
            'evidence' => $encoding !== false ? 'byte-detection' : 'undetermined',
        ];
    }

    /**
     * Marker offsets are evidence only. The original bytes are never stripped or rewritten.
     *
     * @param  array{detected: ?string, evidence: string}  $encoding
     * @return array{header_end_byte: ?int, footer_start_byte: ?int, detector: string}
     */
    private function boilerplateBoundaries(string $contents, array $encoding): array
    {
        if ($encoding['detected'] === null || ! str_starts_with(strtoupper($encoding['detected']), 'UTF-')) {
            return ['header_end_byte' => null, 'footer_start_byte' => null, 'detector' => 'gutenberg-markers-v1'];
        }

        preg_match('/\*{3}\s*START OF (?:THE|THIS) PROJECT GUTENBERG EBOOK.*?\*{3}/i', $contents, $start, PREG_OFFSET_CAPTURE);
        preg_match('/\*{3}\s*END OF (?:THE|THIS) PROJECT GUTENBERG EBOOK.*?\*{3}/i', $contents, $end, PREG_OFFSET_CAPTURE);

        return [
            'header_end_byte' => isset($start[0]) ? $start[0][1] + strlen($start[0][0]) : null,
            'footer_start_byte' => $end[0][1] ?? null,
            'detector' => 'gutenberg-markers-v1',
        ];
    }

    private function cachePath(string $relative): string
    {
        return rtrim($this->cacheDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$relative;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        $decoded = is_string($contents) ? json_decode($contents, true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    private function writeOnce(string $path, string $contents): void
    {
        if (is_file($path)) {
            return;
        }

        $this->ensureDirectory(dirname($path));
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';

        if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
            @unlink($temporary);
            throw new RuntimeException("Unable to preserve Gutenberg artifact at {$path}.");
        }

        if (! @rename($temporary, $path) && ! is_file($path)) {
            @unlink($temporary);
            throw new RuntimeException("Unable to commit Gutenberg artifact at {$path}.");
        }

        @unlink($temporary);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function writeJsonAtomically(string $path, array $value): void
    {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $this->ensureDirectory(dirname($path));
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';

        if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || ! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException("Unable to write Gutenberg acquisition manifest at {$path}.");
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create Gutenberg cache directory {$directory}.");
        }
    }
}
