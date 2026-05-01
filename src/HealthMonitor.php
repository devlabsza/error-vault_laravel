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
        // Use system RAM rather than PHP process memory — the latter is
        // ~constant for an artisan command and never trips alerts.
        $sys = $this->getSystemMemory();
        $memoryUsage = $sys['used'];
        $memoryLimit = $sys['total'];

        if ($memoryLimit <= 0) {
            return null;
        }

        $threshold = $this->config['health_monitoring']['memory_threshold'] ?? 80;
        $usagePercent = ($memoryUsage / $memoryLimit) * 100;

        if ($usagePercent >= $threshold) {
            return [
                'type' => 'memory_pressure',
                'severity' => $usagePercent >= 95 ? 'critical' : 'warning',
                'message' => sprintf(
                    'High system memory usage: %s of %s (%.1f%%)',
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
                    'source' => $sys['source'],
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
            $endpoint = ErrorVault::endpointFor($this->config['api_endpoint'] ?? null, 'health/alert');

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
     * Last error surfaced by sendHealthReport(), so callers (e.g. the
     * errorvault:health-report artisan command) can display a real reason
     * instead of a generic "Failed to send".
     */
    protected ?string $lastError = null;

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Send periodic health report
     */
    public function sendHealthReport(bool $blocking = false): bool
    {
        $this->lastError = null;

        if (!$this->isEnabled()) {
            $this->lastError = 'Health monitoring is not enabled.';
            return false;
        }

        try {
            $endpoint = ErrorVault::endpointFor($this->config['api_endpoint'] ?? null, 'health/report');
            $healthData = $this->getCurrentHealthStatus();

            $healthData['site_url'] = config('app.url');
            $healthData['site_name'] = config('app.name');
            $healthData['plugin_version'] = ErrorVault::VERSION;

            $request = Http::withHeaders([
                'X-API-Token' => $this->config['api_token'],
                'User-Agent' => 'ErrorVault-Laravel/' . ErrorVault::VERSION,
            ])->timeout(10);

            if (!$blocking) {
                $request->async()->post($endpoint, $healthData);
                return true;
            }

            $response = $request->post($endpoint, $healthData);

            if ($response->successful()) {
                return true;
            }

            $body = $response->body();
            $preview = mb_strlen($body) > 300 ? mb_substr($body, 0, 300) . '…' : $body;
            $this->lastError = sprintf('HTTP %d from %s — %s', $response->status(), $endpoint, $preview);
            Log::error('[ErrorVault] Health report failed: ' . $this->lastError);
            return false;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            Log::error('[ErrorVault] Failed to send health report: ' . $this->lastError);
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

        // System RAM (Linux /proc/meminfo or macOS vm_stat). Falls back to
        // PHP process memory only if neither is available — see getSystemMemory().
        $sysMemory = $this->getSystemMemory();
        $memoryUsage = $sysMemory['used'];
        $memoryLimit = $sysMemory['total'];
        $memorySource = $sysMemory['source'];

        // PHP process memory kept around for context — useful for spotting
        // a runaway worker even when the host has plenty of RAM.
        $phpMemoryUsage = memory_get_usage(true);
        $phpMemoryPeak = memory_get_peak_usage(true);
        $phpMemoryLimit = $this->getMemoryLimitBytes();

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
                // System RAM (the meaningful number for "Server Health").
                'usage' => $memoryUsage,
                'usage_formatted' => $this->formatBytes($memoryUsage),
                'limit' => $memoryLimit,
                'limit_formatted' => $this->formatBytes($memoryLimit),
                'usage_percent' => $memoryLimit > 0 ? round(($memoryUsage / $memoryLimit) * 100, 1) : 0,
                'source' => $memorySource,

                // PHP process memory (the worker / artisan command itself).
                // Kept so a runaway script still shows up even when the box
                // has plenty of RAM available.
                'php_usage' => $phpMemoryUsage,
                'php_usage_formatted' => $this->formatBytes($phpMemoryUsage),
                'php_peak' => $phpMemoryPeak,
                'php_peak_formatted' => $this->formatBytes($phpMemoryPeak),
                'php_limit' => $phpMemoryLimit,
                'php_limit_formatted' => $this->formatBytes($phpMemoryLimit),

                // Backwards compatibility: clients that read .peak / .peak_formatted
                // from earlier versions still get a meaningful value.
                'peak' => $phpMemoryPeak,
                'peak_formatted' => $this->formatBytes($phpMemoryPeak),
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
     * Read system-level RAM usage. Returns ['total','available','used',
     * 'used_percent','source'] in bytes (except used_percent).
     *
     * Source priority:
     *   - "proc_meminfo"  — Linux: parses /proc/meminfo (most servers)
     *   - "vm_stat"       — macOS: vm_stat + sysctl hw.memsize (dev boxes)
     *   - "php_fallback"  — last resort: PHP process memory vs memory_limit
     *                        (poor proxy but better than zero)
     */
    protected function getSystemMemory(): array
    {
        $linux = $this->readProcMeminfo();
        if ($linux !== null) {
            return $linux + ['source' => 'proc_meminfo'];
        }

        $mac = $this->readMacMemory();
        if ($mac !== null) {
            return $mac + ['source' => 'vm_stat'];
        }

        // Last-resort fallback so the rest of the report doesn't break.
        $usage = memory_get_usage(true);
        $limit = $this->getMemoryLimitBytes();
        $total = $limit > 0 ? $limit : ($usage * 4); // best-effort denominator
        $usedPercent = $total > 0 ? round(($usage / $total) * 100, 1) : 0;
        return [
            'total' => $total,
            'available' => max(0, $total - $usage),
            'used' => $usage,
            'used_percent' => $usedPercent,
            'source' => 'php_fallback',
        ];
    }

    protected function readProcMeminfo(): ?array
    {
        $path = '/proc/meminfo';
        if (!is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $values = [];
        foreach (preg_split('/\r?\n/', $contents) as $line) {
            if (preg_match('/^([A-Za-z()_]+):\s+([0-9]+)\s+kB/', $line, $m)) {
                $values[$m[1]] = (int) $m[2] * 1024;
            }
        }

        if (empty($values['MemTotal'])) {
            return null;
        }

        // MemAvailable was added in Linux 3.14 — prefer it; otherwise estimate.
        if (isset($values['MemAvailable'])) {
            $available = $values['MemAvailable'];
        } else {
            $available = ($values['MemFree'] ?? 0)
                + ($values['Buffers'] ?? 0)
                + ($values['Cached'] ?? 0);
        }

        $total = $values['MemTotal'];
        $used = max(0, $total - $available);
        return [
            'total' => $total,
            'available' => $available,
            'used' => $used,
            'used_percent' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
        ];
    }

    protected function readMacMemory(): ?array
    {
        if (!function_exists('shell_exec')) {
            return null;
        }

        $sysctl = @shell_exec('sysctl -n hw.memsize 2>/dev/null');
        if (!$sysctl || !is_numeric(trim($sysctl))) {
            return null;
        }
        $total = (int) trim($sysctl);

        $vmStat = @shell_exec('vm_stat 2>/dev/null');
        if (!$vmStat) {
            return null;
        }

        // Page size is in the first line, e.g. "Mach Virtual Memory Statistics:
        // (page size of 16384 bytes)"
        $pageSize = 4096;
        if (preg_match('/page size of (\d+) bytes/', $vmStat, $m)) {
            $pageSize = (int) $m[1];
        }

        $pages = [];
        foreach (preg_split('/\r?\n/', $vmStat) as $line) {
            if (preg_match('/^"?([\w\s]+?)"?:\s+([0-9]+)\.?$/', $line, $m)) {
                $pages[strtolower(trim($m[1]))] = (int) $m[2];
            }
        }

        // Used ≈ active + wired + compressed. Inactive + speculative are
        // reclaimable, so they shouldn't count as "used" for our purposes.
        $active = ($pages['pages active'] ?? 0) * $pageSize;
        $wired = ($pages['pages wired down'] ?? 0) * $pageSize;
        $compressed = ($pages['pages occupied by compressor'] ?? 0) * $pageSize;
        $used = $active + $wired + $compressed;
        $available = max(0, $total - $used);

        return [
            'total' => $total,
            'available' => $available,
            'used' => $used,
            'used_percent' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
        ];
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

    /**
     * Get diagnostics information
     */
    public function getDiagnostics(): array
    {
        $errorVault = app(ErrorVault::class);
        $failures = $errorVault->getConnectionFailures();
        $consecutive = $errorVault->getConsecutiveFailures();

        return [
            'enabled' => $this->isEnabled(),
            'health_monitoring_enabled' => $this->config['health_monitoring']['enabled'] ?? false,
            'consecutive_failures' => $consecutive,
            'total_failures' => count($failures),
            'recent_failures' => array_slice($failures, -5),
            'last_failure' => !empty($failures) ? end($failures) : null,
            'config' => [
                'cpu_threshold' => $this->config['health_monitoring']['cpu_load_threshold'] ?? 80,
                'memory_threshold' => $this->config['health_monitoring']['memory_threshold'] ?? 80,
                'request_rate_threshold' => $this->config['health_monitoring']['request_rate_threshold'] ?? 1000,
                'report_interval' => $this->config['health_monitoring']['report_interval'] ?? 5,
            ],
        ];
    }
}
