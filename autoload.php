<?php

declare(strict_types=1);

/**
 * Zero-dependency* PSR-4 autoloader for the Maildeno namespace.
 *
 * Use this when you are not installing via Composer:
 *
 *   require __DIR__ . '/autoload.php';
 *   use Maildeno\MaildenoClient;
 *
 * * "Zero-dependency" for Maildeno's own classes specifically. Rendering
 * also needs symfony/process autoloadable — a real dependency of
 * NativeEngine, not optional — so this file loads vendor/autoload.php too
 * if it finds one (e.g. you ran `composer require symfony/process` even
 * while skipping Composer for Maildeno itself). If you ARE using Composer
 * for everything, prefer requiring `vendor/autoload.php` directly instead
 * of this file.
 */

// If Composer dependencies are installed, load them.
$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

spl_autoload_register(static function (string $class): void {
    $prefix  = 'Maildeno\\';
    $baseDir = __DIR__ . '/src/';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file     = $baseDir . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});