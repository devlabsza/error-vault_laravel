<?php

namespace ErrorVault\Laravel;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class HealthMonitor
{
    /**
     * Configuration
     */
    protected array $config;

    /**
     * Cache keys
     */
    protected const REQUEST_COUNT_KEY = 'errorvault_request_count';
    protected const REQUEST_IPS_KEY = 'errorvault_request_ips';
    protected const LAST_ALERT_KEY = 'errorvault_last_health_alert';

    /**
     * Constructor
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Check if health monitoring is enabled
     */
    public function isEnabled(): bool
    {
        return ($this->config['health_monitoring']['enabled'] ?? false)
            && !empty($this->config['api_endpoint'])
            && !empty($this->config['api_token']);
    }

    /**
     * Track incoming request
     */
    public function trackRequest(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $window = $this->getTimeWindow();
        $ip = request()->ip();

        // Cache TTL should be longer than the report interval (default 5 min = 300 sec)
        // Use 10 minutes (600 seconds) to ensure data persists between reports
        $cacheTtl = 600;

        // Increment request count
        $countKey = self::REQUEST_COUNT_KEY . '_' . $window;
        $count = (int) Cache::get($countKey, 0);
        Cache::put($countKey, $count + 1, $cacheTtl);

        // Track unique IPs
        $ipsKey = self::REQUEST_IPS_KEY . '_' . $window;
        $ips = Cache::get($ipsKey, []);
        if (!isset($ips[$ip])) {
            $ips[$ip] = 0;
        }
        $ips[$ip]++;
        Cache::put($ipsKey, $ips, $cacheTtl);
    }

    /**
     * Run health checks
     */
    public function runHealthChecks(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $alerts = [];

        // Check CPU load
        $cpuAlert = $this->checkCpuLoad();
        if ($cpuAlert) {
            $alerts[] = $cpuAlert;
        }

        // Check memory usage
        $memoryAlert = $this->checkMemoryUsage();
        if ($memoryAlert) {
            $alerts[] = $memoryAlert;
        }

        // Check request rate (potential DDoS)
        $ddosAlert = $this->checkRequestRate();
        if ($ddosAlert) {
            $alerts[] = $ddosAlert;
        }

        // Send alerts if any (with rate limiting)
        if (!empty($alerts)) {
            $this->sendHealthAlerts($alerts);
        }
    }

    /**
     * Check CPU load
     */
    protected function checkCpuLoad(): ?array
    {
        if (!function_exists('sys_getloadavg')) {
            return null;
        }

        $load = sys_getloadavg();
        if ($load === false) {
            return null;
        }

        $cpuCores = $this->getCpuCores();
        $threshold = $this->config['health_monitoring']['cpu_load_threshold'] ?? 2.0;

        $loadRatio = $cpuCores > 0 ? $load[0] / $cpuCores : $load[0];

        if ($loadRatio >= $threshold) {
            return [
                'type' => 'cpu_overload',
                'severity' => $loadRatio >= ($threshold * 1.5) ? 'critical' : 'warning',
                'message' => sprintf(
                    'High CPU load detected: %.2f (%.1f%% of capacity)',
                    $load[0],
                    $loadRatio * 100
                ),
                'data' => [
                    'load_1min' => $load[0],
                    'load_5min' => $load[1],
                    'load_15min' => $load[2],
                    'cpu_cores' => $cpuCores,
                    'load_ratio' => round($loadRatio, 2),
                    'threshold' => $threshold,
                ],
            ];
        }

        return null;
    }

    /**
     * Check memory usage
     */
    protected function checkMemoryUsage(): ?array
    {
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->getMemoryLimitBytes();

        if ($memoryLimit <= 0) {
            return null; // Unlimited memory
        }

        $threshold = $this->config['health_monitoring']['memory_threshold'] ?? 80;
        $usagePercent = ($memoryUsage / $memoryLimit) * 100;

        if ($usagePercent >= $threshold) {
            return [
                'type' => 'memory_pressure',
                'severity' => $usagePercent >= 95 ? 'critical' : 'warning',
                'message' => sprintf(
                    'High memory usage: %s of %s (%.1f%%)',
                    $this->formatBytes($memoryUsage),
                    $this->formatBytes($memoryLimit),
                    $usagePercent
                ),
                'data' => [
                    'usage' => $memoryUsage,
                    'usage_formatted' => $this->formatBytes($memoryUsage),
                    'limit' => $memoryLimit,
                    'limit_formatted' => $this->formatBytes($memoryLimit),
                    'usage_percent' => round($usagePercent, 1),
                    'threshold' => $threshold,
                ],
            ];
        }

        return null;
    }

    /**
     * Check request rate for potential DDoS
     */
    protected function checkRequestRate(): ?array
    {
        $window = $this->getTimeWindow();
        $prevWindow = $this->getTimeWindow(-1);

        // Get current minute request count
        $currentCount = (int) Cache::get(self::REQUEST_COUNT_KEY . '_' . $window, 0);
        $prevCount = (int) Cache::get(self::REQUEST_COUNT_KEY . '_' . $prevWindow, 0);

        // Get thresholds from config
        $rateThreshold = $this->config['health_monitoring']['request_rate_threshold'] ?? 100;
        $spikeThreshold = $this->config['health_monitoring']['request_spike_threshold'] ?? 3.0;

        // Check absolute threshold
        if ($currentCount >= $rateThreshold) {
            $ips = Cache::get(self::REQUEST_IPS_KEY . '_' . $window, []);
            $uniqueIps = count($ips);
            $topIps = $this->getTopIps($ips, 5);

            return [
                'type' => 'high_request_rate',
                'severity' => $currentCount >= ($rateThreshold * 2) ? 'critical' : 'warning',
                'message' => sprintf(
                    'High request rate detected: %d requests/min from %d unique IPs',
                    $currentCount,
                    $uniqueIps
                ),
                'data' => [
                    'requests_per_minute' => $currentCount,
                    'unique_ips' => $uniqueIps,
                    'top_ips' => $topIps,
                    'threshold' => $rateThreshold,
                    'potential_ddos' => $uniqueIps < 10 && $currentCount > $rateThreshold * 2,
                ],
            ];
        }

        // Check for sudden spike
        if ($prevCount > 10 && $currentCount >= ($prevCount * $spikeThreshold)) {
            $ips = Cache::get(self::REQUEST_IPS_KEY . '_' . $window, []);
            $uniqueIps = count($ips);

            return [
                'type' => 'traffic_spike',
                'severity' => 'warning',
                'message' => sprintf(
                    'Traffic spike detected: %d requests (%.1fx increase)',
                    $currentCount,
                    $currentCount / $prevCount
                ),
                'data' => [
                    'current_rate' => $currentCount,
                    'previous_rate' => $prevCount,
                    'increase_factor' => round($currentCount / $prevCount, 1),
                    'unique_ips' => $uniqueIps,
                ],
            ];
        }

        return null;
    }

    /**
     * Send health alerts to ErrorVault
     */
    protected function sendHealthAlerts(array $alerts): void
    {
        // Rate limit alerts
        $lastAlerts = Cache::get(self::LAST_ALERT_KEY, []);
        $alertsToSend = [];
        $now = time();
        $cooldown = $this->config['health_monitoring']['alert_cooldown'] ?? 300;

        foreach ($alerts as $alert) {
            $type = $alert['type'];

            if (!isset($lastAlerts[$type]) || ($now - $lastAlerts[$type]) >= $cooldown) {
                $alertsToSend[] = $alert;
                $lastAlerts[$type] = $now;
            }
        }

        if (empty($alertsToSend)) {
            return;
        }

        Cache::put(self::LAST_ALERT_KEY, $lastAlerts, 3600);

        // Send each alert
        foreach ($alertsToSend as $alert) {
            $this->sendHealthAlert($alert);
        }
    }

    /**
     * Send a single health alert
     */
    public function sendHealthAlert(array $alertData): bool
    {
        try {
            $endpoint = str_replace('/errors', '/health/alert', rtrim($this->config['api_endpoint'], '/'));

            // Add common context
            $alertData['site_url'] = config('app.url');
            $alertData['site_name'] = config('app.name');
            $alertData['timestamp'] = now()->toIso8601String();
            $alertData['laravel_version'] = app()->version();
            $alertData['php_version'] = PHP_VERSION;
            $alertData['plugin_version'] = ErrorVault::VERSION;

            $response = Http::withHeaders([
                'X-API-Token' => $this->config['api_token'],
                'User-Agent' => 'ErrorVault-Laravel/' . ErrorVault::VERSION,
            ])
            ->timeout(5)
            ->post($endpoint, $alertData);

            return $response->successful();
        } catch (Throwable $e) {
            Log::error('[ErrorVault] Failed to send health alert: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send periodic health report
     */
    public function sendHealthReport(bool $blocking = false): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        try {
            $endpoint = str_replace('/errors', '/health/report', rtrim($this->config['api_endpoint'], '/'));
            $healthData = $this->getCurrentHealthStatus();

            // Add common context
            $healthData['site_url'] = config('app.url');
            $healthData['site_name'] = config('app.name');
            $healthData['plugin_version'] = ErrorVault::VERSION;

            $request = Http::withHeaders([
                'X-API-Token' => $this->config['api_token'],
                'User-Agent' => 'ErrorVault-Laravel/' . ErrorVault::VERSION,
            ])->timeout(10);

            if (!$blocking) {
                // Non-blocking (fire and forget)
                $request->async()->post($endpoint, $healthData);
                return true;
            }

            $response = $request->post($endpoint, $healthData);
            return $response->successful();
        } catch (Throwable $e) {
            Log::error('[ErrorVault] Failed to send health report: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get current health status
     */
    public function getCurrentHealthStatus(): array
    {
        $window = $this->getTimeWindow();
        $prevWindow = $this->getTimeWindow(-1);

        // CPU info
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0];
        $cpuCores = $this->getCpuCores();

        // Memory info
        $memoryUsage = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);
        $memoryLimit = $this->getMemoryLimitBytes();

        // Request info - check both current and previous window to avoid missing data at minute boundaries
        $currentCount = (int) Cache::get(self::REQUEST_COUNT_KEY . '_' . $window, 0);
        $prevCount = (int) Cache::get(self::REQUEST_COUNT_KEY . '_' . $prevWindow, 0);
        $requestCount = max($currentCount, $prevCount); // Use whichever has more data

        $currentIps = Cache::get(self::REQUEST_IPS_KEY . '_' . $window, []);
        $prevIps = Cache::get(self::REQUEST_IPS_KEY . '_' . $prevWindow, []);
        $ips = array_merge($prevIps, $currentIps); // Merge IPs from both windows
        $uniqueIps = count(array_unique(array_keys($ips)));

        // Disk info
        $appPath = base_path();
        $diskFree = function_exists('disk_free_space') ? @disk_free_space($appPath) : 0;
        $diskTotal = function_exists('disk_total_space') ? @disk_total_space($appPath) : 0;

        return [
            'timestamp' => now()->toIso8601String(),
            'cpu' => [
                'load_1min' => $load[0] ?? 0,
                'load_5min' => $load[1] ?? 0,
                'load_15min' => $load[2] ?? 0,
                'cores' => $cpuCores,
                'load_percent' => $cpuCores > 0 ? round(($load[0] / $cpuCores) * 100, 1) : 0,
            ],
            'memory' => [
                'usage' => $memoryUsage,
                'usage_formatted' => $this->formatBytes($memoryUsage),
                'peak' => $memoryPeak,
                'peak_formatted' => $this->formatBytes($memoryPeak),
                'limit' => $memoryLimit,
                'limit_formatted' => $this->formatBytes($memoryLimit),
                'usage_percent' => $memoryLimit > 0 ? round(($memoryUsage / $memoryLimit) * 100, 1) : 0,
            ],
            'disk' => [
                'free' => $diskFree,
                'free_formatted' => $this->formatBytes($diskFree),
                'total' => $diskTotal,
                'total_formatted' => $this->formatBytes($diskTotal),
                'used_percent' => $diskTotal > 0 ? round((($diskTotal - $diskFree) / $diskTotal) * 100, 1) : 0,
            ],
            'traffic' => [
                'requests_per_minute' => $requestCount,
                'unique_ips' => $uniqueIps,
            ],
            'laravel' => [
                'version' => app()->version(),
                'php_version' => PHP_VERSION,
                'environment' => app()->environment(),
            ],
            'status' => $this->calculateOverallStatus($load, $memoryUsage, $memoryLimit, $requestCount),
        ];
    }

    /**
     * Calculate overall health status
     */
    protected function calculateOverallStatus(array $load, int $memoryUsage, int $memoryLimit, int $requestCount): string
    {
        $cpuCores = $this->getCpuCores();
        $loadRatio = $cpuCores > 0 ? $load[0] / $cpuCores : $load[0];
        $memoryPercent = $memoryLimit > 0 ? ($memoryUsage / $memoryLimit) * 100 : 0;

        $rateThreshold = $this->config['health_monitoring']['request_rate_threshold'] ?? 100;

        // Critical conditions
        if ($loadRatio >= 3 || $memoryPercent >= 95 || $requestCount >= ($rateThreshold * 3)) {
            return 'critical';
        }

        // Warning conditions
        if ($loadRatio >= 2 || $memoryPercent >= 80 || $requestCount >= $rateThreshold) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * Get time window (minute-based)
     */
    protected function getTimeWindow(int $offset = 0): int
    {
        return (int) floor(time() / 60) + $offset;
    }

    /**
     * Get top IPs by request count
     */
    protected function getTopIps(array $ips, int $limit = 5): array
    {
        if (empty($ips)) {
            return [];
        }

        arsort($ips);
        $top = array_slice($ips, 0, $limit, true);

        $result = [];
        foreach ($top as $ip => $count) {
            $result[] = [
                'ip' => $this->maskIp($ip),
                'requests' => $count,
            ];
        }

        return $result;
    }

    /**
     * Mask IP address for privacy
     */
    protected function maskIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            return $parts[0] . '.' . $parts[1] . '.xxx.xxx';
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return substr($ip, 0, 10) . ':xxxx:xxxx';
        }
        return 'xxx.xxx.xxx.xxx';
    }

    /**
     * Get number of CPU cores
     */
    protected function getCpuCores(): int
    {
        static $cores = null;

        if ($cores !== null) {
            return $cores;
        }

        // Try /proc/cpuinfo (Linux)
        if (is_file('/proc/cpuinfo')) {
            $cpuinfo = @file_get_contents('/proc/cpuinfo');
            if ($cpuinfo !== false) {
                preg_match_all('/^processor/m', $cpuinfo, $matches);
                $cores = count($matches[0]);
                if ($cores > 0) {
                    return $cores;
                }
            }
        }

        // Try nproc command
        if (function_exists('shell_exec')) {
            $nproc = @shell_exec('nproc 2>/dev/null');
            if ($nproc !== null && is_numeric(trim($nproc))) {
                $cores = (int) trim($nproc);
                return $cores;
            }
        }

        // Try sysctl (macOS)
        if (function_exists('shell_exec')) {
            $sysctl = @shell_exec('sysctl -n hw.ncpu 2>/dev/null');
            if ($sysctl !== null && is_numeric(trim($sysctl))) {
                $cores = (int) trim($sysctl);
                return $cores;
            }
        }

        $cores = 1;
        return $cores;
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

        $value = (int) $limit;
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
}
