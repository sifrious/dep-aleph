<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Configuration;

use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\Connector;
use Sifrious\Aleph\Connector\Contracts\ConfiguresSources;

final readonly class WebCrawlConnector implements ConfiguresSources, Connector
{
    public function __construct(private ?SourceConfigurationRecorder $recorder = null) {}

    public function id(): string
    {
        return 'web-crawl';
    }

    public function name(): string
    {
        return 'Web crawl';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function configuration(): ConfigurationSchema
    {
        return (new WebCrawlConfigurationAdapter)->schema();
    }

    public function configureSource(SourceConfigurationRequest $request): SourceConfiguration
    {
        return (new WebCrawlSourceConfigurator($this, $this->recorder))->configureSource($request);
    }
}
