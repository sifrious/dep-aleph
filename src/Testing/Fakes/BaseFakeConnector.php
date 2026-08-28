<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Testing\Fakes;

use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\Connector;

abstract class BaseFakeConnector implements Connector
{
    public function __construct(
        private readonly string $id,
        private readonly string $name = 'Fake connector',
        private readonly string $version = '1.0.0',
        private readonly ?ConfigurationSchema $configuration = null,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function configuration(): ConfigurationSchema
    {
        return $this->configuration ?? ConfigurationSchema::none();
    }
}
