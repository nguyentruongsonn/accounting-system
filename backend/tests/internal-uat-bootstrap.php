<?php

require __DIR__.'/../vendor/autoload.php';

// A separate bootstrap protects RefreshDatabase from accidental config edits.
foreach ([
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_DATABASE' => 'accounting_uat_20260901',
    'DB_URL' => '',
] as $name => $expected) {
    $actual = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
    if ($actual !== $expected) {
        throw new RuntimeException('Unsafe internal UAT environment: '.$name);
    }
}
if (is_file(__DIR__.'/../bootstrap/cache/internal-uat-no-cache.php')) {
    throw new RuntimeException('Internal UAT must not use cached application configuration.');
}
