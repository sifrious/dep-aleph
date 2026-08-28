<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Shell;

final readonly class ShellCommandTokenizer
{
    /**
     * @return list<string>
     */
    public function tokenize(string $command, int $limit = 256): array
    {
        $command = trim($command);
        $tokens = [];
        $current = '';
        $single = false;
        $double = false;
        $length = strlen($command);

        for ($index = 0; $index < $length; $index++) {
            $character = $command[$index];

            if (! $single && $character === '"') {
                $double = ! $double;

                continue;
            }

            if (! $double && $character === "'") {
                $single = ! $single;

                continue;
            }

            if (! $single && $character === '\\' && $index + 1 < $length) {
                $current .= $command[++$index];

                continue;
            }

            if (! $single && ! $double && ctype_space($character)) {
                if ($current !== '') {
                    $tokens[] = $current;
                    $current = '';
                }

                if (count($tokens) === $limit) {
                    return $tokens;
                }

                continue;
            }

            $current .= $character;
        }

        if ($current !== '' && count($tokens) < $limit) {
            $tokens[] = $current;
        }

        return $tokens;
    }
}
