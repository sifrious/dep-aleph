<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

use InvalidArgumentException;
use Sifrious\Aleph\Ingestion\IngestionRun;

final readonly class CrawlParameters
{
    /**
     * @param  list<string>  $hosts
     */
    public function __construct(
        public CrawlLimits $limits,
        public array $hosts,
    ) {}

    public static function of(WebSource $source): self
    {
        return new self($source->limits, $source->hosts->restrictions());
    }

    public static function fromRun(IngestionRun $run): self
    {
        $limits = $run->parameters['limits'] ?? null;
        $hosts = $run->parameters['hosts'] ?? null;

        if (! is_array($limits) || ! is_int($limits['max_pages'] ?? null) || ! is_int($limits['max_depth'] ?? null)) {
            throw new InvalidArgumentException("Ingestion run [{$run->id}] has invalid crawl limits.");
        }

        if (! is_array($hosts) || array_filter($hosts, fn (mixed $host): bool => ! is_string($host)) !== []) {
            throw new InvalidArgumentException("Ingestion run [{$run->id}] has invalid host restrictions.");
        }

        return new self(new CrawlLimits($limits['max_pages'], $limits['max_depth']), array_values($hosts));
    }

    public function applyTo(WebSource $source): WebSource
    {
        return $source->withLimits($this->limits)->restrictedToHosts($this->hosts);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['limits' => $this->limits->toArray(), 'hosts' => $this->hosts];
    }
}
