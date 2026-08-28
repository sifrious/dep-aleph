<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Shell;

interface ShellHistorySource
{
    public function sourceReference(): string;

    public function scan(?string $cursor): ShellHistoryScan;
}
