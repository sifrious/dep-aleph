<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Configuration;

use Sifrious\Aleph\Connector\ConnectorInstallation;

final readonly class ConfiguredSource
{
    public function __construct(
        public SourceConfiguration $configuration,
        public ConnectorInstallation $installation,
    ) {}

    /**
     * The reference every later run, checkpoint, and submission uses for this source.
     */
    public function sourceReference(): string
    {
        return $this->configuration->sourceReference;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_reference' => $this->sourceReference(),
            'configuration' => $this->configuration->toArray(),
            'installation' => $this->installation->toArray(),
        ];
    }
}
