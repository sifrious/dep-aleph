<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

final class UrlCanonicalizer
{
    private const REFERENCE = '#^(?:([^:/?\#]+):)?(?://([^/?\#]*))?([^?\#]*)(?:\?([^\#]*))?(?:\#(.*))?$#';

    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    private const SUPPORTED_SCHEMES = ['http', 'https'];

    /**
     * @param  list<string>  $allowedQueryParameters
     */
    public function __construct(private readonly array $allowedQueryParameters = []) {}

    public function canonicalize(string $reference, ?CanonicalUrl $base = null): ?CanonicalUrl
    {
        $parts = $this->parse(trim($reference));

        if ($parts === null) {
            return null;
        }

        $target = $this->resolve($parts, $base);

        if ($target === null) {
            return null;
        }

        $scheme = strtolower((string) $target['scheme']);

        if (! in_array($scheme, self::SUPPORTED_SCHEMES, true)) {
            return null;
        }

        $authority = $this->normalizeAuthority($target['authority'], $scheme);

        if ($authority === null) {
            return null;
        }

        [$host, $port] = $authority;

        $path = $target['path'] === '' ? '/' : $target['path'];
        $query = $this->normalizeQuery($target['query']);

        $value = $scheme.'://'.$host
            .($port !== null ? ':'.$port : '')
            .$path
            .($query !== null ? '?'.$query : '');

        return new CanonicalUrl($value, $scheme, $host, $port, $path, $query);
    }

    /**
     * @return array{scheme: ?string, authority: ?string, path: string, query: ?string}|null
     */
    private function parse(string $reference): ?array
    {
        if ($reference === '' || preg_match('/[\x00-\x20]/', $reference) === 1) {
            return null;
        }

        if (preg_match(self::REFERENCE, $reference, $matches, PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }

        return [
            'scheme' => $matches[1],
            'authority' => $matches[2],
            'path' => $matches[3],
            'query' => $matches[4],
        ];
    }

    /**
     * @param  array{scheme: ?string, authority: ?string, path: string, query: ?string}  $reference
     * @return array{scheme: ?string, authority: ?string, path: string, query: ?string}|null
     */
    private function resolve(array $reference, ?CanonicalUrl $base): ?array
    {
        if ($reference['scheme'] !== null) {
            $reference['path'] = $this->removeDotSegments($reference['path']);

            return $reference;
        }

        if ($base === null) {
            return null;
        }

        $baseParts = $this->parse($base->value);

        if ($baseParts === null) {
            return null;
        }

        if ($reference['authority'] !== null) {
            return [
                'scheme' => $baseParts['scheme'],
                'authority' => $reference['authority'],
                'path' => $this->removeDotSegments($reference['path']),
                'query' => $reference['query'],
            ];
        }

        if ($reference['path'] === '') {
            return [
                'scheme' => $baseParts['scheme'],
                'authority' => $baseParts['authority'],
                'path' => $baseParts['path'],
                'query' => $reference['query'] ?? $baseParts['query'],
            ];
        }

        $path = str_starts_with($reference['path'], '/')
            ? $reference['path']
            : $this->merge($baseParts, $reference['path']);

        return [
            'scheme' => $baseParts['scheme'],
            'authority' => $baseParts['authority'],
            'path' => $this->removeDotSegments($path),
            'query' => $reference['query'],
        ];
    }

    /**
     * @param  array{scheme: ?string, authority: ?string, path: string, query: ?string}  $base
     */
    private function merge(array $base, string $path): string
    {
        if ($base['authority'] !== null && $base['path'] === '') {
            return '/'.$path;
        }

        $slash = strrpos($base['path'], '/');

        return $slash === false ? $path : substr($base['path'], 0, $slash + 1).$path;
    }

    private function removeDotSegments(string $path): string
    {
        $output = [];

        while ($path !== '') {
            if (str_starts_with($path, '../')) {
                $path = substr($path, 3);
            } elseif (str_starts_with($path, './')) {
                $path = substr($path, 2);
            } elseif (str_starts_with($path, '/./')) {
                $path = '/'.substr($path, 3);
            } elseif ($path === '/.') {
                $path = '/';
            } elseif (str_starts_with($path, '/../')) {
                array_pop($output);
                $path = '/'.substr($path, 4);
            } elseif ($path === '/..') {
                array_pop($output);
                $path = '/';
            } elseif ($path === '.' || $path === '..') {
                $path = '';
            } else {
                $slash = strpos($path, '/', 1);

                if ($slash === false) {
                    $output[] = $path;
                    $path = '';
                } else {
                    $output[] = substr($path, 0, $slash);
                    $path = substr($path, $slash);
                }
            }
        }

        return implode('', $output);
    }

    /**
     * @return array{0: string, 1: ?int}|null
     */
    private function normalizeAuthority(?string $authority, string $scheme): ?array
    {
        if ($authority === null || $authority === '') {
            return null;
        }

        $at = strrpos($authority, '@');

        if ($at !== false) {
            $authority = substr($authority, $at + 1);
        }

        $port = null;
        $colon = strrpos($authority, ':');

        if ($colon !== false && ! str_contains(substr($authority, $colon), ']')) {
            $portText = substr($authority, $colon + 1);
            $authority = substr($authority, 0, $colon);

            if ($portText !== '') {
                if (preg_match('/^\d+$/', $portText) !== 1) {
                    return null;
                }

                $port = (int) $portText;
            }
        }

        $host = rtrim(strtolower($authority), '.');

        if ($host === '') {
            return null;
        }

        if ($port !== null && $port === (self::DEFAULT_PORTS[$scheme] ?? null)) {
            $port = null;
        }

        return [$host, $port];
    }

    private function normalizeQuery(?string $query): ?string
    {
        if ($query === null || $query === '' || $this->allowedQueryParameters === []) {
            return null;
        }

        $kept = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            $equals = strpos($pair, '=');
            $name = urldecode($equals === false ? $pair : substr($pair, 0, $equals));

            if (! in_array($name, $this->allowedQueryParameters, true)) {
                continue;
            }

            $kept[] = [$name, $pair];
        }

        if ($kept === []) {
            return null;
        }

        usort($kept, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return implode('&', array_column($kept, 1));
    }
}
