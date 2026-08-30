<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

/**
 * Lists concrete language implementations for one ingest capability.
 *
 * Language is provenance on a run, not a second history model. Availability is
 * checked at resolve time so a missing language is an explicit refuse.
 */
final class IngestAdapterRegistry
{
    /** @var array<string, array<string, bool>> */
    private array $adapters = [];

    public function register(string $capability, IngestLanguage $language, bool $available = true): void
    {
        if (! $language->isConcrete()) {
            throw new LaunchRejected(
                'language_invalid',
                'Ingest adapter registry entries must be concrete languages (php or python).',
            );
        }

        $this->adapters[$capability][$language->value] = $available;
    }

    public function has(string $capability, IngestLanguage $language): bool
    {
        return $language->isConcrete()
            && array_key_exists($language->value, $this->adapters[$capability] ?? []);
    }

    public function available(string $capability, IngestLanguage $language): bool
    {
        return ($this->adapters[$capability][$language->value] ?? false) === true;
    }

    /**
     * @return list<IngestLanguage>
     */
    public function languages(string $capability): array
    {
        $languages = [];

        foreach (array_keys($this->adapters[$capability] ?? []) as $value) {
            $languages[] = IngestLanguage::from($value);
        }

        return $languages;
    }

    /**
     * @return list<IngestLanguage>
     */
    public function availableLanguages(string $capability): array
    {
        $languages = [];

        foreach ($this->adapters[$capability] ?? [] as $value => $available) {
            if ($available) {
                $languages[] = IngestLanguage::from($value);
            }
        }

        return $languages;
    }

    public function resolve(string $capability, IngestLanguage $requested): IngestLanguage
    {
        if ($requested === IngestLanguage::Any) {
            foreach ([IngestLanguage::Php, IngestLanguage::Python] as $candidate) {
                if ($this->available($capability, $candidate)) {
                    return $candidate;
                }
            }

            throw new LaunchRejected(
                'language_unavailable',
                "No ingest language is available for capability [{$capability}].",
            );
        }

        if (! $this->has($capability, $requested)) {
            throw new LaunchRejected(
                'language_unsupported',
                "Capability [{$capability}] does not list ingest language [{$requested->value}].",
            );
        }

        if (! $this->available($capability, $requested)) {
            throw new LaunchRejected(
                'language_unavailable',
                "Ingest language [{$requested->value}] is not available for capability [{$capability}].",
            );
        }

        return $requested;
    }
}
