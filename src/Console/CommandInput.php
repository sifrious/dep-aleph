<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Console;

use InvalidArgumentException;
use JsonException;

final class CommandInput
{
    /**
     * @param  array<int, mixed>  $pairs
     * @return array<string, mixed>
     */
    public static function pairs(array $pairs): array
    {
        $values = [];

        foreach ($pairs as $pair) {
            if (! is_string($pair) || ! str_contains($pair, '=')) {
                throw new InvalidArgumentException('Values must use key=value syntax.');
            }

            [$key, $value] = explode('=', $pair, 2);
            $key = trim($key);

            if ($key === '') {
                throw new InvalidArgumentException('Value keys cannot be empty.');
            }

            $values[$key] = self::value($value);
        }

        return $values;
    }

    private static function value(string $value): mixed
    {
        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $value;
        }
    }
}
