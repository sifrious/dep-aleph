<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Configuration;

/**
 * Records a configuration declaration as an observation. Implementations receive a record
 * that already excludes credential material.
 */
interface SourceConfigurationRecorder
{
    public function record(SourceConfiguration $configuration): void;
}
