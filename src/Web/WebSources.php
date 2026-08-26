<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

final readonly class WebSources
{
    /**
     * @param  array<string, mixed>  $sources
     */
    public function __construct(private array $sources) {}

    public function get(string $key): WebSource
    {
        $config = $this->sources[$key] ?? null;

        if (! is_array($config)) {
            throw UnknownWebSource::named($key, $this->names());
        }

        return WebSource::fromArray($key, $config);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->sources);
    }
}
