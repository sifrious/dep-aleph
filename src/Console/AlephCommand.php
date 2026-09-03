<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Console;

use Illuminate\Console\Command;
use JsonException;
use Throwable;

abstract class AlephCommand extends Command
{
    /** @param array<mixed> $value */
    protected function json(array $value): int
    {
        try {
            $this->line(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (JsonException $failure) {
            return $this->failure($failure);
        }

        return self::SUCCESS;
    }

    protected function failure(Throwable $failure): int
    {
        $this->components->error($failure->getMessage());

        return self::FAILURE;
    }

    protected function display(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        try {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return '[unprintable]';
        }
    }
}
