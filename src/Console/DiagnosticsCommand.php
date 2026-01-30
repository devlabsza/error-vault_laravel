<?php

namespace ErrorVault\Laravel\Console;

use ErrorVault\Laravel\ErrorVault;
use ErrorVault\Laravel\HealthMonitor;
use Illuminate\Console\Command;

class DiagnosticsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'errorvault:diagnostics 
                            {--clear-failures : Clear all connection failure logs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display ErrorVault diagnostics and status information';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $errorVault = app(ErrorVault::class);
        $healthMonitor = app(HealthMonitor::class);

        // Handle clear failures option
        if ($this->option('clear-failures')) {
            $errorVault->clearFailureLog();
            $this->info('✓ Connection failure logs cleared.');
            return self::SUCCESS;
        }

        // Display header
        $this->info('ErrorVault Diagnostics');
        $this->line('Package Version: ' . ErrorVault::VERSION);
        $this->newLine();

        // Configuration Status
        $this->line('<fg=cyan>Configuration Status:</>');
        $this->line('  Enabled: ' . ($errorVault->isEnabled() ? '<fg=green>Yes</>' : '<fg=red>No</>'));
        $this->line('  API Endpoint: ' . (config('errorvault.api_endpoint') ?: '<fg=yellow>Not set</>'));
        $this->line('  API Token: ' . (config('errorvault.api_token') ? '<fg=green>Set</>' : '<fg=red>Not set</>'));
        $this->newLine();

        // Health Monitoring Status
        $diagnostics = $healthMonitor->getDiagnostics();
        $this->line('<fg=cyan>Health Monitoring:</>');
        $this->line('  Enabled: ' . ($diagnostics['health_monitoring_enabled'] ? '<fg=green>Yes</>' : '<fg=yellow>No</>'));
        if ($diagnostics['health_monitoring_enabled']) {
            $this->line('  CPU Threshold: ' . $diagnostics['config']['cpu_threshold'] . '%');
            $this->line('  Memory Threshold: ' . $diagnostics['config']['memory_threshold'] . '%');
            $this->line('  Request Rate Threshold: ' . $diagnostics['config']['request_rate_threshold'] . ' req/min');
            $this->line('  Report Interval: ' . $diagnostics['config']['report_interval'] . ' minutes');
        }
        $this->newLine();

        // Connection Failures
        $this->line('<fg=cyan>Connection Status:</>');
        $this->line('  Consecutive Failures: ' . ($diagnostics['consecutive_failures'] > 0 ? '<fg=red>' . $diagnostics['consecutive_failures'] . '</>' : '<fg=green>0</>'));
        $this->line('  Total Failures Logged: ' . $diagnostics['total_failures']);
        
        if (!empty($diagnostics['recent_failures'])) {
            $this->newLine();
            $this->line('<fg=cyan>Recent Failures:</>');
            
            $headers = ['Timestamp', 'Type', 'Message'];
            $rows = [];
            
            foreach ($diagnostics['recent_failures'] as $failure) {
                $rows[] = [
                    $failure['timestamp'],
                    $failure['type'],
                    substr($failure['message'], 0, 60) . (strlen($failure['message']) > 60 ? '...' : ''),
                ];
            }
            
            $this->table($headers, $rows);
        } else {
            $this->line('  <fg=green>No recent failures</>');
        }

        $this->newLine();

        // Scheduled Tasks Info
        $this->line('<fg=cyan>Scheduled Tasks:</>');
        $this->line('  Heartbeat: Every 5 minutes (keeps site active)');
        if ($diagnostics['health_monitoring_enabled']) {
            $this->line('  Health Report: Every ' . $diagnostics['config']['report_interval'] . ' minutes');
        }
        $this->newLine();

        // Helpful commands
        $this->line('<fg=cyan>Available Commands:</>');
        $this->line('  php artisan errorvault:test-connection  - Test connection to portal');
        $this->line('  php artisan errorvault:diagnostics      - Show this diagnostics page');
        $this->line('  php artisan errorvault:diagnostics --clear-failures - Clear failure logs');
        $this->line('  php artisan errorvault:health-report    - Send health report manually');

        return self::SUCCESS;
    }
}
