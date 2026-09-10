<?php

$applicationEnvironment = strtolower(trim((string) env('APP_ENV', 'production')));
$isLocalDevelopment = in_array($applicationEnvironment, ['local', 'testing'], true);
$configuredOrigins = env('CORS_ALLOWED_ORIGINS');
$configuredOriginList = $configuredOrigins === null || trim($configuredOrigins) === ''
    ? []
    : array_values(array_filter(array_map('trim', explode(',', $configuredOrigins))));

// Config files are loaded before the application has bound the `env`
// container key.  Resolve the environment from the config-safe env helper
// instead of calling app()->environment() during bootstrap.  Only local and
// test runtimes retain the developer wildcard; every deployed environment
// fails closed when its explicit origin allowlist is absent or contains '*'.
$allowedOrigins = $configuredOriginList;
if ($isLocalDevelopment && $allowedOrigins === []) {
    $allowedOrigins = ['*'];
}
if (! $isLocalDevelopment && in_array('*', $allowedOrigins, true)) {
    $allowedOrigins = [];
}

$configuredHeaders = env('CORS_ALLOWED_HEADERS');
$allowedHeaders = $configuredHeaders === null || trim($configuredHeaders) === ''
    ? ['Accept', 'Authorization', 'Content-Type', 'Origin', 'X-Requested-With', 'X-CSRF-TOKEN', 'X-XSRF-TOKEN']
    : array_values(array_filter(array_map('trim', explode(',', $configuredHeaders))));

$supportsCredentials = filter_var(env('CORS_SUPPORTS_CREDENTIALS', false), FILTER_VALIDATE_BOOL);
if ($supportsCredentials && in_array('*', $allowedOrigins, true)) {
    // Browsers reject wildcard origins with credentials; fail closed rather
    // than emitting a misleading or deployment-dependent CORS policy.
    $allowedOrigins = [];
}

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // Keep the local developer default compatible with the current bearer
    // client. Deployed environments require an explicit origin allowlist.
    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => $allowedHeaders,

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => $supportsCredentials,

];
