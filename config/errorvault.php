<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable ErrorVault
    |--------------------------------------------------------------------------
    |
    | Set to true to enable error reporting to ErrorVault. You can also
    | disable this in specific environments using environment variables.
    |
    */

    'enabled' => env('ERRORVAULT_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | API Endpoint
    |--------------------------------------------------------------------------
    |
    | The ErrorVault API endpoint. Defaults to the hosted service so most
    | users only need to set ERRORVAULT_API_TOKEN. Override this if you
    | run a self-hosted portal.
    |
    */

    'api_endpoint' => env('ERRORVAULT_API_ENDPOINT', 'https://error-vault.com/api/v1/errors'),

    /*
    |--------------------------------------------------------------------------
    | API Token
    |--------------------------------------------------------------------------
    |
    | Your site's unique API token from the ErrorVault portal.
    |
    */

    'api_token' => env('ERRORVAULT_API_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Send Immediately
    |--------------------------------------------------------------------------
    |
    | When true, errors are sent immediately when they occur.
    | When false, errors are batched and sent at the end of the request.
    |
    */

    'send_immediately' => env('ERRORVAULT_SEND_IMMEDIATELY', true),

    /*
    |--------------------------------------------------------------------------
    | Log Levels
    |--------------------------------------------------------------------------
    |
    | Control which severity levels are reported to ErrorVault.
    |
    */

    'log_notices' => env('ERRORVAULT_LOG_NOTICES', false),

    'log_warnings' => env('ERRORVAULT_LOG_WARNINGS', true),

    'log_deprecations' => env('ERRORVAULT_LOG_DEPRECATIONS', false),

    /*
    |--------------------------------------------------------------------------
    | Minimum Severity
    |--------------------------------------------------------------------------
    |
    | Only report errors at or above this severity level.
    | Options: notice, warning, error, critical, fatal
    |
    */

    'minimum_severity' => env('ERRORVAULT_MINIMUM_SEVERITY', 'warning'),

    /*
    |--------------------------------------------------------------------------
    | Ignored Exceptions
    |--------------------------------------------------------------------------
    |
    | Exception classes that should not be reported to ErrorVault.
    |
    */

    'ignore' => [
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Auth\Access\AuthorizationException::class,
        \Symfony\Component\HttpKernel\Exception\HttpException::class,
        \Illuminate\Database\Eloquent\ModelNotFoundException::class,
        \Illuminate\Session\TokenMismatchException::class,
        \Illuminate\Validation\ValidationException::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignored Patterns
    |--------------------------------------------------------------------------
    |
    | Error messages containing these strings will not be reported.
    |
    */

    'ignore_patterns' => [
        // Add patterns here, e.g., 'deprecated'
    ],

    /*
    |--------------------------------------------------------------------------
    | Severity Map
    |--------------------------------------------------------------------------
    |
    | Map specific exception classes to severity levels.
    | Valid severities: notice, warning, error, critical, fatal
    |
    */

    'severity_map' => [
        // \App\Exceptions\CustomException::class => 'warning',
    ],

    /*
    |--------------------------------------------------------------------------
    | Scrub Query & Context Keys
    |--------------------------------------------------------------------------
    |
    | Before an error is sent, these query-string parameter names and context
    | array keys are replaced with "[FILTERED]". Add any names that might
    | carry secrets in your app (for example signed URLs, auth tokens,
    | or third-party callback credentials).
    |
    */

    'scrub_keys' => [
        'password', 'password_confirmation', 'token', 'api_token',
        'api_key', 'apikey', 'secret', 'access_token', 'refresh_token',
        'authorization', 'auth', 'signature', 'signed', 'key',
        'x-api-token', 'x-api-key', 'cookie', 'session',
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup
    |--------------------------------------------------------------------------
    |
    | When enabled, a scheduled command polls the ErrorVault portal every
    | few minutes. If a backup has been requested from the dashboard, the
    | package dumps the database, archives the project (excluding paths
    | listed in exclude_paths), and uploads the zip via chunked multipart
    | upload using the same API the WordPress plugin uses.
    |
    */

    'backup' => [

        // Master switch. Also gates the scheduled poll.
        'enabled' => env('ERRORVAULT_BACKUP_ENABLED', false),

        // How often (minutes) the scheduled task polls /backups/pending.
        'poll_interval' => env('ERRORVAULT_BACKUP_POLL_INTERVAL', 5),

        // Upload chunk size in megabytes. The portal's minimum part size is
        // 5MB; don't drop below that except for the final chunk.
        'chunk_size_mb' => env('ERRORVAULT_BACKUP_CHUNK_SIZE_MB', 5),

        // Working directory for intermediate files. Must be writable. The
        // manager creates it on demand and always cleans up afterwards.
        'tmp_path' => env('ERRORVAULT_BACKUP_TMP_PATH', storage_path('app/errorvault-backup')),

        // Whether to include storage/app in the archive. Often large and
        // already replicated elsewhere (S3 etc.); default off.
        'include_storage' => env('ERRORVAULT_BACKUP_INCLUDE_STORAGE', false),

        // Paths (relative to base_path()) that are always excluded from the
        // archive. Override the whole list via config publishing if you need
        // a different set.
        'exclude_paths' => [
            'node_modules',
            'vendor',
            '.git',
            'storage/framework/cache',
            'storage/framework/sessions',
            'storage/framework/views',
            'storage/logs',
            'storage/app/errorvault-backup',
            'bootstrap/cache',
            '.env',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Monitoring
    |--------------------------------------------------------------------------
    |
    | Enable server health monitoring to detect CPU overloads, memory pressure,
    | high request rates, and potential DDoS attacks.
    |
    */

    'health_monitoring' => [

        // Enable or disable health monitoring
        'enabled' => env('ERRORVAULT_HEALTH_ENABLED', false),

        // CPU load threshold (percentage, 0-100)
        // Alert when CPU load exceeds this percentage
        'cpu_load_threshold' => env('ERRORVAULT_CPU_THRESHOLD', 80),

        // Memory usage threshold (percentage, 0-100)
        // Alert when memory usage exceeds this percentage
        'memory_threshold' => env('ERRORVAULT_MEMORY_THRESHOLD', 80),

        // Request rate threshold (requests per minute)
        // Alert when request rate exceeds this threshold
        'request_rate_threshold' => env('ERRORVAULT_REQUEST_RATE_THRESHOLD', 1000),

        // Request spike multiplier
        // Alert when current rate exceeds baseline by this multiplier
        'request_spike_multiplier' => env('ERRORVAULT_SPIKE_MULTIPLIER', 5),

        // Alert cooldown in minutes
        // Prevent duplicate alerts within this time window
        'alert_cooldown' => env('ERRORVAULT_ALERT_COOLDOWN', 15),

        // Health report interval (in minutes)
        // How often to send health reports to the portal
        'report_interval' => env('ERRORVAULT_HEALTH_INTERVAL', 5),

        // Disk usage threshold (percentage, 0-100)
        // Alert when disk usage exceeds this percentage
        'disk_threshold' => env('ERRORVAULT_DISK_THRESHOLD', 90),

    ],

];
