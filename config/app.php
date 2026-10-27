<?php
declare(strict_types=1);

function load_env_file(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, "\"'");

        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

function env_value(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

load_env_file(dirname(__DIR__) . '/.env');

define('APP_NAME', 'TurnOK');
define('APP_ENV', env_value('APP_ENV', 'production'));
define('APP_URL', rtrim((string) env_value('APP_URL', ''), '/'));
define('APP_TIMEZONE', 'America/Argentina/Buenos_Aires');

date_default_timezone_set(APP_TIMEZONE);
