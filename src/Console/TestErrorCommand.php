<?php

namespace ErrorVault\Laravel\Console;

use ErrorVault\Laravel\ErrorVault;
use Illuminate\Console\Command;

class TestErrorCommand extends Command
{
    protected $signature = 'errorvault:test-error
                            {--severity=warning : notice|warning|error|critical|fatal}
                            {--message= : Custom message for the test error}';

    protected $description = 'Send a sample error to ErrorVault so you can verify the pipeline end-to-end.';

    public function handle(): int
    {
        $errorVault = app(ErrorVault::class);

        if (!$errorVault->isEnabled()) {
            $this->error('ErrorVault is not enabled. Set ERRORVAULT_ENABLED=true and provide ERRORVAULT_API_TOKEN.');
            return self::FAILURE;
        }

        $severity = strtolower((string) $this->option('severity'));
        $allowed = ['notice', 'warning', 'error', 'critical', 'fatal'];

        if (!in_array($severity, $allowed, true)) {
            $this->error("Invalid severity '{$severity}'. Allowed: " . implode(', ', $allowed));
            return self::FAILURE;
        }

        $message = (string) ($this->option('message') ?: 'Test error from ErrorVault Laravel package');

        $this->info('Sending test error to ErrorVault...');
        $this->line("  Severity: {$severity}");
        $this->line("  Message:  {$message}");
        $this->newLine();

        $sent = $errorVault->reportError($message, $severity, __FILE__, __LINE__, [
            'test' => true,
            'dispatched_via' => 'artisan errorvault:test-error',
            'dispatched_at' => now()->toIso8601String(),
        ]);

        if ($sent) {
            $this->info('✓ Test error accepted by ErrorVault. It should appear in the dashboard shortly.');
            return self::SUCCESS;
        }

        $this->error('✗ Test error was not accepted. Check the Laravel log or run `php artisan errorvault:diagnostics`.');
        return self::FAILURE;
    }
}
