<?php

namespace ErrorVault\Laravel;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class ErrorVault
{
    /**
     * Package version
     */
    public const VERSION = '1.3.3';

    /**
     * Configuration
     */
    protected array $config;

    /**
     * Error buffer for batch sending
     */
    protected array $buffer = [];

    /**
     * Health monitor instance
     */
    protected ?HealthMonitor $healthMonitor = null;

    /**
     * Cache keys for connection failure tracking
     */
    protected const FAILURE_LOG_KEY = 'errorvault_connection_failures';
    protected const CONSECUTIVE_FAILURES_KEY = 'errorvault_consecutive_failures';
    protected const LAST_NOTIFICATION_KEY = 'errorvault_last_failure_notification';

    /**
     * Constructor
     */
    public function __construct(array $config)
    {
        $this->config = $config;

        // Register shutdown function for batch sending
        if (!($config['send_immediately'] ?? true)) {
            register_shutdown_function([$this, 'flush']);
        }
    }

    /**
     * Set the health monitor instance
     */
    public function setHealthMonitor(HealthMonitor $monitor): self
    {
        $this->healthMonitor = $monitor;
        return $this;
    }

    /**
     * Get the health monitor instance
     */
    public function getHealthMonitor(): ?HealthMonitor
    {
        return $this->healthMonitor;
    }

    /**
     * Check if health monitoring is enabled
     */
    public function isHealthMonitoringEnabled(): bool
    {
        return $this->healthMonitor?->isEnabled() ?? false;
    }

    /**
     * Track a request for health monitoring
     */
    public function trackRequest(): void
    {
        $this->healthMonitor?->trackRequest();
    }

    /**
     * Run health checks and send alerts if needed
     */
    public function runHealthChecks(): void
    {
        $this->healthMonitor?->runHealthChecks();
    }

    /**
     * Send a health report to the portal
     */
    public function sendHealthReport(bool $blocking = false): bool
    {
        return $this->healthMonitor?->sendHealthReport($blocking) ?? false;
    }

    /**
     * Get current health status
     */
    public function getCurrentHealthStatus(): array
    {
        return $this->healthMonitor?->getCurrentHealthStatus() ?? [];
    }

    /**
     * Get configuration value
     */
    public function config(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Check if logging is enabled
     */
    public function isEnabled(): bool
    {
        return $this->config('enabled', false)
            && !empty($this->config('api_endpoint'))
            && !empty($this->config('api_token'));
    }

    /**
     * Report an exception
     */
    public function report(Throwable $exception, array $context = []): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        // Check if this exception should be ignored
        if ($this->shouldIgnore($exception)) {
            return false;
        }

        $errorData = $this->buildErrorData($exception, $context);

        if ($this->config('send_immediately', true)) {
            return $this->send($errorData);
        }

        $this->buffer[] = $errorData;
        return true;
    }

    /**
     * Report an error manually
     */
    public function reportError(
        string $message,
        string $severity = 'error',
        ?string $file = null,
        ?int $line = null,
        array $context = []
    ): bool {
        if (!$this->isEnabled()) {
            return false;
        }

        $errorData = [
            'message' => $message,
            'severity' => $severity,
            'file' => $file,
            'line' => $line,
            'stack_trace' => $this->getStackTrace(),
            'context' => $this->buildContext($context),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'url' => $this->getCurrentUrl(),
            'request_method' => request()->method(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ];

        if ($this->config('send_immediately', true)) {
            return $this->send($errorData);
        }

        $this->buffer[] = $errorData;
        return true;
    }

    /**
     * Build error data from exception
     */
    protected function buildErrorData(Throwable $exception, array $context = []): array
    {
        return [
            'message' => $exception->getMessage(),
            'severity' => $this->getSeverity($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'stack_trace' => $exception->getTraceAsString(),
            'context' => $this->buildContext($context),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'url' => $this->getCurrentUrl(),
            'request_method' => request()->method(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ];
    }

    /**
     * Build context data
     */
    protected function buildContext(array $additionalContext = []): array
    {
        $context = [
            'environment' => app()->environment(),
        ];

        // Add user info if available
        if (auth()->check()) {
            $user = auth()->user();
            $context['user'] = [
                'id' => $user->id ?? null,
                'email' => $user->email ?? null,
            ];
        }

        // Add route info
        if ($route = request()->route()) {
            $context['route'] = [
                'name' => $route->getName(),
                'action' => $route->getActionName(),
            ];
        }

        // Add comprehensive memory info
        $context['memory_info'] = $this->getMemoryInfo();

        // Add server info
        $context['server_info'] = $this->getServerInfo();

        // Merge additional context, then scrub anything that looks like a secret.
        return $this->scrubArray(array_merge($context, $additionalContext));
    }

    /**
     * Get comprehensive memory information
     */
    protected function getMemoryInfo(): array
    {
        $memoryUsage = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);
        $memoryLimit = $this->getMemoryLimitBytes();

        $memory = [
            'usage' => $memoryUsage,
            'usage_formatted' => $this->formatBytes($memoryUsage),
            'peak' => $memoryPeak,
            'peak_formatted' => $this->formatBytes($memoryPeak),
        ];

        if ($memoryLimit > 0) {
            $memory['limit'] = $memoryLimit;
            $memory['limit_formatted'] = $this->formatBytes($memoryLimit);
            $memory['usage_percent'] = round(($memoryUsage / $memoryLimit) * 100, 1);
            $memory['peak_percent'] = round(($memoryPeak / $memoryLimit) * 100, 1);
        }

        return $memory;
    }

    /**
     * Get server information
     */
    protected function getServerInfo(): array
    {
        $server = [
            'hostname' => gethostname() ?: 'unknown',
            'os' => PHP_OS,
            'php_sapi' => PHP_SAPI,
        ];

        // Get load average (Linux/Unix only)
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if ($load !== false) {
                $server['load_average'] = [
                    '1min' => round($load[0], 2),
                    '5min' => round($load[1], 2),
                    '15min' => round($load[2], 2),
                ];
            }
        }

        // Try to get CPU cores
        $cpuCores = $this->getCpuCores();
        if ($cpuCores > 0) {
            $server['cpu_cores'] = $cpuCores;
        }

        // Get execution time
        if (defined('LARAVEL_START')) {
            $server['execution_time'] = round((microtime(true) - LARAVEL_START) * 1000, 2);
            $server['execution_time_formatted'] = $server['execution_time'] . 'ms';
        }

        // Max execution time
        $maxExecTime = (int)ini_get('max_execution_time');
        $server['max_execution_time'] = $maxExecTime;

        // Get disk space for app directory
        $appPath = base_path();
        if (function_exists('disk_free_space') && @disk_free_space($appPath) !== false) {
            $freeSpace = @disk_free_space($appPath);
            $totalSpace = @disk_total_space($appPath);

            if ($freeSpace !== false && $totalSpace !== false) {
                $server['disk'] = [
                    'free' => $freeSpace,
                    'free_formatted' => $this->formatBytes($freeSpace),
                    'total' => $totalSpace,
                    'total_formatted' => $this->formatBytes($totalSpace),
                    'used_percent' => round((($totalSpace - $freeSpace) / $totalSpace) * 100, 1),
                ];
            }
        }

        // Database query count if available
        if (app()->bound('db')) {
            try {
                $queryLog = \DB::getQueryLog();
                $server['db_queries'] = count($queryLog);
            } catch (\Throwable $e) {
                // Query logging might not be enabled
            }
        }

        return $server;
    }

    /**
     * Get memory limit in bytes
     */
    protected function getMemoryLimitBytes(): int
    {
        $limit = ini_get('memory_limit');

        if ($limit === '-1') {
            return 0; // Unlimited
        }

        $value = (int)$limit;
        $unit = strtolower(substr($limit, -1));

        switch ($unit) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * Get number of CPU cores
     */
    protected function getCpuCores(): int
    {
        // Try different methods
        if (is_file('/proc/cpuinfo')) {
            $cpuinfo = @file_get_contents('/proc/cpuinfo');
            if ($cpuinfo !== false) {
                preg_match_all('/^processor/m', $cpuinfo, $matches);
                return count($matches[0]);
            }
        }

        // Try nproc command
        if (function_exists('shell_exec')) {
            $nproc = @shell_exec('nproc 2>/dev/null');
            if ($nproc !== null && is_numeric(trim($nproc))) {
                return (int)trim($nproc);
            }
        }

        return 0;
    }

    /**
     * Format bytes to human readable
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Get severity from exception
     */
    protected function getSeverity(Throwable $exception): string
    {
        $class = get_class($exception);

        // Map specific exception types to severity
        $severityMap = $this->config('severity_map', []);

        if (isset($severityMap[$class])) {
            return $severityMap[$class];
        }

        // Default mappings
        if ($exception instanceof \Error) {
            return 'fatal';
        }

        if ($exception instanceof \ErrorException) {
            return match ($exception->getSeverity()) {
                E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR => 'fatal',
                E_RECOVERABLE_ERROR => 'critical',
                E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => 'warning',
                E_NOTICE, E_USER_NOTICE, E_STRICT, E_DEPRECATED, E_USER_DEPRECATED => 'notice',
                default => 'error',
            };
        }

        return 'error';
    }

    /**
     * Check if exception should be ignored
     */
    protected function shouldIgnore(Throwable $exception): bool
    {
        // Check ignored exception classes
        $ignore = $this->config('ignore', []);

        foreach ($ignore as $class) {
            if ($exception instanceof $class) {
                return true;
            }
        }

        // Check ignore patterns
        $patterns = $this->config('ignore_patterns', []);
        $message = $exception->getMessage();

        foreach ($patterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        // Check severity level settings
        $severity = $this->getSeverity($exception);

        if (!$this->shouldLogSeverity($severity)) {
            return true;
        }

        return false;
    }

    /**
     * Check if a severity level should be logged based on config
     */
    protected function shouldLogSeverity(string $severity): bool
    {
        // Check minimum severity
        $minimumSeverity = $this->config('minimum_severity', 'warning');
        $severityLevels = ['notice' => 1, 'warning' => 2, 'error' => 3, 'critical' => 4, 'fatal' => 5];

        $currentLevel = $severityLevels[$severity] ?? 3;
        $minimumLevel = $severityLevels[$minimumSeverity] ?? 2;

        if ($currentLevel < $minimumLevel) {
            return false;
        }

        // Check specific toggles
        if ($severity === 'notice' && !$this->config('log_notices', false)) {
            return false;
        }

        if ($severity === 'warning' && !$this->config('log_warnings', true)) {
            return false;
        }

        // Check deprecations (they come through as notices)
        if ($severity === 'notice' && !$this->config('log_deprecations', false)) {
            // Could add deprecation detection here if needed
        }

        return true;
    }

    /**
     * Send error to API
     */
    protected function send(array $errorData): bool
    {
        try {
            $response = Http::withHeaders([
                'X-API-Token' => $this->config('api_token'),
                'User-Agent' => 'ErrorVault-Laravel/' . self::VERSION,
            ])
            ->timeout(5)
            ->post($this->config('api_endpoint'), $errorData);

            if ($response->successful()) {
                $this->clearConnectionFailures();
                return true;
            }

            $this->logConnectionFailure('error_send', 'HTTP ' . $response->status() . ': ' . $response->body());
            return false;
        } catch (Throwable $e) {
            $this->logConnectionFailure('error_send', $e->getMessage());
            logger()->error('[ErrorVault] Failed to send error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Flush buffer (send batch)
     */
    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        try {
            $endpoint = rtrim($this->config('api_endpoint'), '/') . '/batch';

            Http::withHeaders([
                'X-API-Token' => $this->config('api_token'),
                'User-Agent' => 'ErrorVault-Laravel/' . self::VERSION,
            ])
            ->timeout(10)
            ->post($endpoint, ['errors' => $this->buffer]);

            $this->buffer = [];
        } catch (Throwable $e) {
            logger()->error('[ErrorVault] Failed to send batch: ' . $e->getMessage());
        }
    }

    /**
     * Get current URL with sensitive query params scrubbed.
     */
    protected function getCurrentUrl(): string
    {
        try {
            return $this->scrubUrl(request()->fullUrl());
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Replace any query param whose name appears in the scrub list with [FILTERED].
     */
    protected function scrubUrl(string $url): string
    {
        $qPos = strpos($url, '?');
        if ($qPos === false) {
            return $url;
        }

        $base = substr($url, 0, $qPos);
        $fragment = '';
        $queryPart = substr($url, $qPos + 1);

        if (($hashPos = strpos($queryPart, '#')) !== false) {
            $fragment = substr($queryPart, $hashPos);
            $queryPart = substr($queryPart, 0, $hashPos);
        }

        $scrubKeys = array_map('strtolower', $this->scrubKeys());
        $pairs = explode('&', $queryPart);

        foreach ($pairs as $i => $pair) {
            if ($pair === '') {
                continue;
            }
            [$rawKey, $rawValue] = array_pad(explode('=', $pair, 2), 2, '');
            $decodedKey = strtolower(urldecode($rawKey));
            if (in_array($decodedKey, $scrubKeys, true)) {
                $pairs[$i] = $rawKey . '=' . urlencode('[FILTERED]');
            }
        }

        $newQuery = implode('&', array_filter($pairs, fn ($p) => $p !== ''));
        return $base . ($newQuery === '' ? '' : '?' . $newQuery) . $fragment;
    }

    /**
     * Recursively scrub keys in an array that look like secrets.
     */
    protected function scrubArray(array $data): array
    {
        $scrubKeys = array_map('strtolower', $this->scrubKeys());

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $scrubKeys, true)) {
                $data[$key] = '[FILTERED]';
                continue;
            }
            if (is_array($value)) {
                $data[$key] = $this->scrubArray($value);
            }
        }

        return $data;
    }

    protected function scrubKeys(): array
    {
        return (array) $this->config('scrub_keys', []);
    }

    /**
     * Get stack trace
     */
    protected function getStackTrace(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $trace = array_slice($trace, 2); // Remove this method and caller

        $output = [];
        foreach ($trace as $i => $frame) {
            $file = $frame['file'] ?? '[internal]';
            $line = $frame['line'] ?? 0;
            $class = isset($frame['class']) ? $frame['class'] . $frame['type'] : '';
            $function = $frame['function'] ?? '';

            $output[] = "#{$i} {$file}({$line}): {$class}{$function}()";
        }

        return implode("\n", $output);
    }

    /**
     * Verify API connection
     */
    public function verify(): array
    {
        try {
            $endpoint = str_replace('/errors', '/verify', rtrim($this->config('api_endpoint'), '/'));

            $response = Http::withHeaders([
                'X-API-Token' => $this->config('api_token'),
                'User-Agent' => 'ErrorVault-Laravel/' . self::VERSION,
            ])
            ->timeout(10)
            ->get($endpoint);

            $data = $response->json();

            if ($response->successful() && ($data['success'] ?? false)) {
                return [
                    'success' => true,
                    'data' => $data['data'] ?? [],
                ];
            }

            return [
                'success' => false,
                'error' => $data['error'] ?? 'Unknown error',
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get statistics
     */
    public function stats(): ?array
    {
        try {
            $endpoint = str_replace('/errors', '/stats', rtrim($this->config('api_endpoint'), '/'));

            $response = Http::withHeaders([
                'X-API-Token' => $this->config('api_token'),
                'User-Agent' => 'ErrorVault-Laravel/' . self::VERSION,
            ])
            ->timeout(10)
            ->get($endpoint);

            $data = $response->json();

            if ($response->successful() && ($data['success'] ?? false)) {
                return $data['data'];
            }

            return null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Send heartbeat/ping to keep site active
     */
    public function sendPing(bool $blocking = false): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        try {
            $endpoint = str_replace('/errors', '/ping', rtrim($this->config('api_endpoint'), '/'));

            $request = Http::withHeaders([
                'X-API-Token' => $this->config('api_token'),
                'User-Agent' => 'ErrorVault-Laravel/' . self::VERSION,
            ])->timeout(5);

            if (!$blocking) {
                // Non-blocking for performance
                $request->async()->post($endpoint, [
                    'timestamp' => now()->toIso8601String(),
                ]);
                return true;
            }

            $response = $request->post($endpoint, [
                'timestamp' => now()->toIso8601String(),
            ]);

            if ($response->successful()) {
                $this->clearConnectionFailures();
                return true;
            }

            $this->logConnectionFailure('ping', 'HTTP ' . $response->status());
            return false;
        } catch (Throwable $e) {
            if ($blocking) {
                $this->logConnectionFailure('ping', $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Log a connection failure
     */
    protected function logConnectionFailure(string $type, string $message): void
    {
        $failures = Cache::get(self::FAILURE_LOG_KEY, []);

        $failures[] = [
            'type' => $type,
            'message' => $message,
            'timestamp' => now()->toDateTimeString(),
            'time' => time(),
        ];

        // Keep only last 20 failures
        $failures = array_slice($failures, -20);
        Cache::put(self::FAILURE_LOG_KEY, $failures, 86400); // 24 hours

        // Log to Laravel log
        logger()->error('[ErrorVault] Connection failure (' . $type . '): ' . $message);

        // Track consecutive failures
        $consecutive = (int) Cache::get(self::CONSECUTIVE_FAILURES_KEY, 0);
        Cache::put(self::CONSECUTIVE_FAILURES_KEY, $consecutive + 1, 3600); // 1 hour

        // Send admin notification after 5 consecutive failures (once per day)
        if ($consecutive >= 4) { // Will be 5 after increment
            $this->notifyAdminOfFailures();
        }
    }

    /**
     * Clear connection failures on successful connection
     */
    protected function clearConnectionFailures(): void
    {
        Cache::forget(self::CONSECUTIVE_FAILURES_KEY);
    }

    /**
     * Get connection failure log
     */
    public function getConnectionFailures(): array
    {
        return Cache::get(self::FAILURE_LOG_KEY, []);
    }

    /**
     * Clear all connection failure logs
     */
    public function clearFailureLog(): void
    {
        Cache::forget(self::FAILURE_LOG_KEY);
        Cache::forget(self::CONSECUTIVE_FAILURES_KEY);
        Cache::forget(self::LAST_NOTIFICATION_KEY);
    }

    /**
     * Get consecutive failure count
     */
    public function getConsecutiveFailures(): int
    {
        return (int) Cache::get(self::CONSECUTIVE_FAILURES_KEY, 0);
    }

    /**
     * Notify admin of persistent connection failures
     */
    protected function notifyAdminOfFailures(): void
    {
        // Rate limit notifications to once per day
        $lastNotification = Cache::get(self::LAST_NOTIFICATION_KEY, 0);
        if (time() - $lastNotification < 86400) {
            return;
        }

        Cache::put(self::LAST_NOTIFICATION_KEY, time(), 86400);

        $failures = $this->getConnectionFailures();
        $consecutive = $this->getConsecutiveFailures();

        // Log critical error
        logger()->critical('[ErrorVault] Persistent connection failures detected', [
            'consecutive_failures' => $consecutive,
            'total_failures' => count($failures),
            'recent_failures' => array_slice($failures, -5),
        ]);

        // You could also send an email notification here if needed
        // Mail::to(config('errorvault.admin_email'))->send(new ConnectionFailureNotification($failures));
    }

    /**
     * Test connection to ErrorVault portal
     */
    public function testConnection(): array
    {
        $results = [
            'ping' => false,
            'verify' => false,
            'errors' => [],
        ];

        // Test ping endpoint
        try {
            $pingResult = $this->sendPing(true);
            $results['ping'] = $pingResult;
            if (!$pingResult) {
                $failures = $this->getConnectionFailures();
                $lastFailure = !empty($failures) ? end($failures) : null;
                $results['errors']['ping'] = $lastFailure['message'] ?? 'Unknown error';
            }
        } catch (Throwable $e) {
            $results['errors']['ping'] = $e->getMessage();
        }

        // Test verify endpoint
        try {
            $verifyResult = $this->verify();
            $results['verify'] = $verifyResult['success'] ?? false;
            if (!$results['verify']) {
                $results['errors']['verify'] = $verifyResult['error'] ?? 'Unknown error';
            }
        } catch (Throwable $e) {
            $results['errors']['verify'] = $e->getMessage();
        }

        $results['success'] = $results['ping'] && $results['verify'];
        $results['timestamp'] = now()->toDateTimeString();

        return $results;
    }
}
