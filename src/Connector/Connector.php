<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector;

interface Connector
{
    public function id(): string;

    public function name(): string;

    public function version(): string;

    public function configuration(): ConfigurationSchema;
}
