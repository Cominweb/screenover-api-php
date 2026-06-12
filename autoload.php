<?php

/**
 * Standalone PSR-4 autoloader for the ScreenOver API wrapper.
 *
 * Use this when you are NOT relying on Composer:
 *
 *     require __DIR__ . '/autoload.php';
 *     $client = new Screenover\Api\ScreenoverApi(...);
 *
 * If you use Composer, require `vendor/autoload.php` instead.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Screenover\\Api\\';
    $baseDir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
