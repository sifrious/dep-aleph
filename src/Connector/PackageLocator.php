<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector;

use ReflectionClass;

final class PackageLocator
{
    /** @var array<class-string, string|null> */
    private static array $cache = [];

    public static function for(object $connector): ?string
    {
        $class = $connector::class;

        if (array_key_exists($class, self::$cache)) {
            return self::$cache[$class];
        }

        return self::$cache[$class] = self::locate($class);
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    /**
     * @param  class-string  $class
     */
    private static function locate(string $class): ?string
    {
        $file = (new ReflectionClass($class))->getFileName();

        if ($file === false) {
            return null;
        }

        $directory = dirname($file);

        while ($directory !== dirname($directory)) {
            $manifest = $directory.'/composer.json';

            if (is_readable($manifest)) {
                $decoded = json_decode((string) file_get_contents($manifest), true);

                if (is_array($decoded) && is_string($decoded['name'] ?? null)) {
                    return $decoded['name'];
                }
            }

            $directory = dirname($directory);
        }

        return null;
    }
}
