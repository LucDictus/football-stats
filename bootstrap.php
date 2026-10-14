<?php

declare(strict_types=1);

// Tiny PSR-4 style autoloader for the App\ namespace (src/).
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Load .env (KEY=value per line). Real environment variables win.
$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (getenv($key) === false) {
            putenv($key . '=' . trim($value, "\"'"));
        }
    }
}

function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);

    return $value === false || $value === '' ? $default : $value;
}
