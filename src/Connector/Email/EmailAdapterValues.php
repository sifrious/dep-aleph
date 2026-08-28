<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Email;

use DateTimeImmutable;

final class EmailAdapterValues
{
    /**
     * @param  array<mixed>  $headers
     * @return array<string, list<string>>
     */
    public static function headers(array $headers): array
    {
        $values = [];

        foreach ($headers as $header) {
            if (! is_array($header)) {
                continue;
            }

            $name = is_string($header['name'] ?? null) ? strtolower($header['name']) : '';
            $value = is_string($header['value'] ?? null) ? $header['value'] : null;

            if ($name !== '' && $value !== null) {
                $values[$name][] = $value;
            }
        }

        return $values;
    }

    /** @param array<string, list<string>> $headers */
    public static function header(array $headers, string $name): ?string
    {
        return $headers[strtolower($name)][0] ?? null;
    }

    /** @return list<EmailParticipant> */
    public static function participants(string $role, ?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $participants = [];

        foreach (str_getcsv($value) as $original) {
            if (! is_string($original)) {
                continue;
            }

            $original = trim($original);
            $address = null;
            $name = null;

            if (preg_match('/^(.*?)<([^>]+)>$/', $original, $match) === 1) {
                $name = trim($match[1], " \t\n\r\0\x0B\"");
                $address = strtolower(trim($match[2]));
            } elseif (filter_var($original, FILTER_VALIDATE_EMAIL) !== false) {
                $address = strtolower($original);
            }

            if ($original !== '') {
                $participants[] = new EmailParticipant($role, $original, $address, $name === '' ? null : $name);
            }
        }

        return $participants;
    }

    /** @param array<string, mixed> $value */
    public static function participant(string $role, array $value): ?EmailParticipant
    {
        $address = $value['address'] ?? $value['emailAddress']['address'] ?? null;
        $name = $value['name'] ?? $value['emailAddress']['name'] ?? null;

        if (! is_string($address) || trim($address) === '') {
            return null;
        }

        $original = is_string($name) && $name !== '' ? $name.' <'.$address.'>' : $address;

        return new EmailParticipant($role, $original, strtolower($address), is_string($name) ? $name : null);
    }

    public static function date(mixed $value): ?DateTimeImmutable
    {
        try {
            return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    public static function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, is_string(...))) : [];
    }
}
