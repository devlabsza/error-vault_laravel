<?php

namespace ErrorVault\Laravel\Console;

use ErrorVault\Laravel\ErrorVault;
use Illuminate\Console\Command;

class TestConnectionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'errorvault:test-connection';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test connection to ErrorVault portal';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $errorVault = app(ErrorVault::class);

        if (!$errorVault->isEnabled()) {
            $this->error('ErrorVault is not enabled. Please check your configuration.');
            return self::FAILURE;
        }

        $this->info('Testing connection to ErrorVault portal...');
        $this->newLine();

        $results = $errorVault->testConnection();

        // Display results
        $this->line('Ping Test: ' . ($results['ping'] ? '<fg=green>✓ Success</>' : '<fg=red>✗ Failed</>'));
        if (!$results['ping'] && isset($results['errors']['ping'])) {
            $this->line('  Error: ' . $results['errors']['ping']);
        }

        $this->line('Verify Test: ' . ($results['verify'] ? '<fg=green>✓ Success</>' : '<fg=red>✗ Failed</>'));
        if (!$results['verify'] && isset($results['errors']['verify'])) {
            $this->line('  Error: ' . $results['errors']['verify']);
        }

        $this->newLine();

        if ($results['success']) {
            $this->info('✓ Connection successful!');
            $this->line('Timestamp: ' . $results['timestamp']);
            return self::SUCCESS;
        } else {
            $this->error('✗ Connection failed. Please check your API endpoint and token.');
            
            // Show recent failures
            $failures = $errorVault->getConnectionFailures();
            if (!empty($failures)) {
                $this->newLine();
                $this->warn('Recent connection failures:');
                foreach (array_slice($failures, -3) as $failure) {
                    $this->line('  [' . $failure['timestamp'] . '] ' . $failure['type'] . ': ' . $failure['message']);
                }
            }
            
            return self::FAILURE;
        }
    }
}
