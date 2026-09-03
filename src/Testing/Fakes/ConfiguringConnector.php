<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Testing\Fakes;

use Sifrious\Aleph\Connector\Configuration\SourceConfiguration;
use Sifrious\Aleph\Connector\Configuration\SourceConfigurationRecorder;
use Sifrious\Aleph\Connector\Configuration\SourceConfigurationRequest;
use Sifrious\Aleph\Connector\Configuration\WebCrawlConfigurationAdapter;
use Sifrious\Aleph\Connector\Configuration\WebCrawlSourceConfigurator;
use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\Contracts\ConfiguresSources;

final class ConfiguringConnector extends BaseFakeConnector implements ConfiguresSources
{
    private readonly WebCrawlSourceConfigurator $configurator;

    /**
     * @param  null|callable(string): (string|null)  $environment
     */
    public function __construct(
        string $id = 'configuring',
        ?SourceConfigurationRecorder $recorder = null,
        ?callable $environment = null,
    ) {
        parent::__construct($id, 'Configuring connector');

        $this->configurator = new WebCrawlSourceConfigurator($this, $recorder, $environment);
    }

    public function configuration(): ConfigurationSchema
    {
        return (new WebCrawlConfigurationAdapter)->schema();
    }

    public function configureSource(SourceConfigurationRequest $request): SourceConfiguration
    {
        return $this->configurator->configureSource($request);
    }
}
