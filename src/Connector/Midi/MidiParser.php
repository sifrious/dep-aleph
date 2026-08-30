<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Midi;

interface MidiParser
{
    public function parse(string $contents): MidiParseResult;
}
