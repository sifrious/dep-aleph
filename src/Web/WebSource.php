<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class WebSource
{
    /**
     * @param  list<string>  $seeds
     * @param  list<string>  $excluded
     * @param  list<string>  $allowedQueryParameters
     * @param  list<string>  $calendarSignals
     */
    public function __construct(
        public string $key,
        public string $name,
        public array $seeds,
        public HostPolicy $hosts,
        public array $excluded,
        public array $allowedQueryParameters,
        public array $calendarSignals,
        public CrawlLimits $limits,
    ) {
        if ($seeds === []) {
            throw new InvalidArgumentException("Web source [{$key}] must declare at least one seed.");
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(string $key, array $config): self
    {
        $limits = is_array($config['limits'] ?? null) ? $config['limits'] : [];

        return new self(
            key: $key,
            name: is_string($config['name'] ?? null) ? $config['name'] : $key,
            seeds: self::strings($config['seeds'] ?? [], "aleph.web_sources.{$key}.seeds"),
            hosts: new HostPolicy(self::strings($config['allowed_hosts'] ?? [], "aleph.web_sources.{$key}.allowed_hosts")),
            excluded: self::strings($config['excluded'] ?? [], "aleph.web_sources.{$key}.excluded"),
            allowedQueryParameters: self::strings(
                $config['query_parameters'] ?? [],
                "aleph.web_sources.{$key}.query_parameters",
            ),
            calendarSignals: self::strings(
                $config['calendar_signals'] ?? [],
                "aleph.web_sources.{$key}.calendar_signals",
            ),
            limits: new CrawlLimits(
                maxPages: is_int($limits['max_pages'] ?? null) ? $limits['max_pages'] : 100,
                maxDepth: is_int($limits['max_depth'] ?? null) ? $limits['max_depth'] : 2,
            ),
        );
    }

    public function reference(): string
    {
        return "web:{$this->key}";
    }

    public function canonicalizer(): UrlCanonicalizer
    {
        return new UrlCanonicalizer($this->allowedQueryParameters);
    }

    public function excludes(CanonicalUrl $url): bool
    {
        if ($this->excluded === []) {
            return false;
        }

        $target = $url->path.($url->query !== null ? '?'.$url->query : '');

        return Str::is($this->excluded, $target);
    }

    public function looksLikeCalendar(CanonicalUrl $url): bool
    {
        return $this->calendarSignals !== [] && Str::is($this->calendarSignals, $url->path);
    }

    public function withLimits(CrawlLimits $limits): self
    {
        return new self(
            $this->key,
            $this->name,
            $this->seeds,
            $this->hosts,
            $this->excluded,
            $this->allowedQueryParameters,
            $this->calendarSignals,
            $limits,
        );
    }

    /**
     * @param  list<string>  $hosts
     */
    public function restrictedToHosts(array $hosts): self
    {
        return new self(
            $this->key,
            $this->name,
            $this->seeds,
            $this->hosts->restrictTo($hosts),
            $this->excluded,
            $this->allowedQueryParameters,
            $this->calendarSignals,
            $this->limits,
        );
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values, string $path): array
    {
        if (! is_array($values)) {
            throw new InvalidArgumentException("Configuration [{$path}] must be an array of strings.");
        }

        foreach ($values as $value) {
            if (! is_string($value)) {
                throw new InvalidArgumentException("Configuration [{$path}] must be an array of strings.");
            }
        }

        return array_values($values);
    }
}
