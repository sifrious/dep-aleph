<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Configuration;

use InvalidArgumentException;
use Sifrious\Aleph\Connector\ConfigurationField;
use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\CredentialKind;
use Sifrious\Aleph\Web\WebSource;

/**
 * Translates a crawl bound set into the neutral configuration record. The bounds that used to
 * be read ad hoc from `aleph.web_sources` are declared here as schema fields instead.
 */
final readonly class WebCrawlConfigurationAdapter implements SourceConfigurationProvider
{
    public function sourceKind(): string
    {
        return 'web';
    }

    public function schema(): ConfigurationSchema
    {
        return new ConfigurationSchema(
            ConfigurationField::list('seeds', 'URLs a crawl starts from.', required: true)
                ->fromEnv('ALEPH_WEB_SEEDS'),
            ConfigurationField::list('allowed_hosts', 'Hosts a crawl may retrieve, with wildcards.', required: true)
                ->fromEnv('ALEPH_WEB_ALLOWED_HOSTS'),
            ConfigurationField::list('excluded', 'Path patterns a crawl never retrieves.')
                ->fromEnv('ALEPH_WEB_EXCLUDED')
                ->withDefault([]),
            ConfigurationField::list('query_parameters', 'Query parameters kept during canonicalization.')
                ->fromEnv('ALEPH_WEB_QUERY_PARAMETERS')
                ->withDefault([]),
            ConfigurationField::list('calendar_signals', 'Path patterns treated as calendar pages.')
                ->fromEnv('ALEPH_WEB_CALENDAR_SIGNALS')
                ->withDefault([]),
            ConfigurationField::number('max_pages', 'Pages a single crawl may retrieve.', required: false)
                ->fromEnv('ALEPH_WEB_MAX_PAGES')
                ->withDefault(100),
            ConfigurationField::number('max_depth', 'Link depth a crawl may follow from a seed.', required: false)
                ->fromEnv('ALEPH_WEB_MAX_DEPTH')
                ->withDefault(2),
        );
    }

    public function credentialKind(): ?CredentialKind
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function bound(array $values): array
    {
        try {
            $source = WebSource::fromArray('bounds', [
                'name' => 'bounds',
                'seeds' => $values['seeds'] ?? [],
                'allowed_hosts' => $values['allowed_hosts'] ?? [],
                'excluded' => $values['excluded'] ?? [],
                'query_parameters' => $values['query_parameters'] ?? [],
                'calendar_signals' => $values['calendar_signals'] ?? [],
                'limits' => [
                    'max_pages' => $values['max_pages'] ?? 100,
                    'max_depth' => $values['max_depth'] ?? 2,
                ],
            ]);
        } catch (InvalidArgumentException $rejection) {
            throw SourceConfigurationRejected::outOfBounds($rejection->getMessage());
        }

        return [
            'seeds' => $source->seeds,
            'allowed_hosts' => $source->hosts->allowed(),
            'excluded' => $source->excluded,
            'query_parameters' => $source->allowedQueryParameters,
            'calendar_signals' => $source->calendarSignals,
            'limits' => $source->limits->toArray(),
        ];
    }
}
