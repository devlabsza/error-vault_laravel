<?php

namespace ErrorVault\Laravel\Console;

use ErrorVault\Laravel\ErrorVault;
use ErrorVault\Laravel\HealthMonitor;
use Illuminate\Console\Command;

class SendHealthReportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'errorvault:health-report
                            {--check : Also run health checks before sending report}
                            {--status : Only display current health status, don\'t send report}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a health report to the ErrorVault portal';

    /**
     * Execute the console command.
     */
    public function handle(ErrorVault $errorVault): int
    {
        if (!$errorVault->isEnabled()) {
            $this->error('ErrorVault is not enabled. Please configure your API endpoint and token.');
            return Command::FAILURE;
        }

        if (!$errorVault->isHealthMonitoringEnabled()) {
            $this->error('Health monitoring is not enabled. Set ERRORVAULT_HEALTH_ENABLED=true in your .env file.');
            return Command::FAILURE;
        }

        // If --status flag is passed, just display current status
        if ($this->option('status')) {
            return $this->displayStatus($errorVault);
        }

        // If --check flag is passed, run health checks first
        if ($this->option('check')) {
            $this->info('Running health checks...');
            $errorVault->runHealthChecks();
            $this->info('Health checks completed.');
        }

        // Send health report
        $this->info('Sending health report to ErrorVault portal...');

        $success = $errorVault->sendHealthReport(true); // blocking mode

        if ($success) {
            $this->info('Health report sent successfully.');
            return Command::SUCCESS;
        }

        $this->error('Failed to send health report. Check your configuration and connection.');
        return Command::FAILURE;
    }

    /**
     * Display current health status
     */
    protected function displayStatus(ErrorVault $errorVault): int
    {
        $status = $errorVault->getCurrentHealthStatus();

        if (empty($status)) {
            $this->warn('No health status available.');
            return Command::SUCCESS;
        }

        $this->info('Current Server Health Status');
        $this->newLine();

        // Overall status
        $statusColor = match ($status['status'] ?? 'unknown') {
            'healthy' => 'green',
            'warning' => 'yellow',
            'critical' => 'red',
            default => 'white',
        };
        $this->line("  <fg={$statusColor}>Status: " . strtoupper($status['status'] ?? 'unknown') . "</>");
        $this->newLine();

        // CPU
        if (isset($status['cpu'])) {
            $cpu = $status['cpu'];
            $cpuColor = ($cpu['load_percent'] ?? 0) >= 80 ? 'red' : (($cpu['load_percent'] ?? 0) >= 60 ? 'yellow' : 'green');
            $this->line("  <fg=cyan>CPU:</>  <fg={$cpuColor}>{$cpu['load_percent']}%</> (Load: {$cpu['load_1min']}/{$cpu['load_5min']}/{$cpu['load_15min']}, Cores: {$cpu['cores']})");
        }

        // Memory
        if (isset($status['memory'])) {
            $mem = $status['memory'];
            $memColor = ($mem['usage_percent'] ?? 0) >= 90 ? 'red' : (($mem['usage_percent'] ?? 0) >= 70 ? 'yellow' : 'green');
            $this->line("  <fg=cyan>Memory:</> <fg={$memColor}>{$mem['usage_percent']}%</> ({$mem['usage_formatted']} / {$mem['limit_formatted']})");
        }

        // Disk
        if (isset($status['disk'])) {
            $disk = $status['disk'];
            $diskColor = ($disk['used_percent'] ?? 0) >= 90 ? 'red' : (($disk['used_percent'] ?? 0) >= 75 ? 'yellow' : 'green');
            $this->line("  <fg=cyan>Disk:</>  <fg={$diskColor}>{$disk['used_percent']}%</> ({$disk['free_formatted']} free)");
        }

        // Traffic (if available)
        if (isset($status['traffic'])) {
            $traffic = $status['traffic'];
            $this->line("  <fg=cyan>Traffic:</> {$traffic['requests_per_minute']} req/min ({$traffic['unique_ips']} unique IPs)");
        }

        $this->newLine();

        return Command::SUCCESS;
    }
}
