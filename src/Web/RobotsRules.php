<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

final readonly class RobotsRules
{
    /**
     * @param  list<array{0: bool, 1: string}>  $rules
     */
    private function __construct(
        private array $rules,
        public ?float $crawlDelay,
    ) {}

    public static function allowAll(): self
    {
        return new self([], null);
    }

    public static function disallowAll(): self
    {
        return new self([[false, '/']], null);
    }

    public static function parse(string $text, string $agentToken): self
    {
        $groups = [];
        $current = null;
        $startNewGroup = true;

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            $colon = strpos($line, ':');

            if ($line === '' || $colon === false) {
                continue;
            }

            $field = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));

            if ($field === 'user-agent') {
                if ($startNewGroup || $current === null) {
                    $current = new RobotsGroup;
                    $groups[] = $current;
                    $startNewGroup = false;
                }

                $current->agents[] = strtolower($value);

                continue;
            }

            if ($current === null) {
                continue;
            }

            $startNewGroup = true;

            if ($field === 'disallow') {
                $current->rules[] = [false, $value];
            } elseif ($field === 'allow') {
                $current->rules[] = [true, $value];
            } elseif ($field === 'crawl-delay' && is_numeric($value)) {
                $current->crawlDelay = (float) $value;
            }
        }

        return self::select($groups, strtolower($agentToken));
    }

    public function allows(string $path): bool
    {
        $verdict = null;
        $bestLength = -1;

        foreach ($this->rules as [$allow, $pattern]) {
            if ($pattern === '' || ! self::matches($pattern, $path)) {
                continue;
            }

            $length = strlen($pattern);

            if ($length > $bestLength || ($length === $bestLength && $allow)) {
                $bestLength = $length;
                $verdict = $allow;
            }
        }

        return $verdict ?? true;
    }

    /**
     * @param  list<RobotsGroup>  $groups
     */
    private static function select(array $groups, string $agentToken): self
    {
        $specific = null;
        $wildcard = null;

        foreach ($groups as $group) {
            if ($specific === null && $group->matches($agentToken)) {
                $specific = $group;
            }

            if ($wildcard === null && $group->isWildcard()) {
                $wildcard = $group;
            }
        }

        $chosen = $specific ?? $wildcard;

        return $chosen === null
            ? self::allowAll()
            : new self($chosen->rules, $chosen->crawlDelay);
    }

    private static function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');

        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }

        $regex = '#^'.str_replace('\*', '.*', preg_quote($pattern, '#')).($anchored ? '$' : '').'#';

        return preg_match($regex, $path) === 1;
    }
}
