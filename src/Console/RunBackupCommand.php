<?php

namespace ErrorVault\Laravel\Console;

use ErrorVault\Laravel\Backup\BackupManager;
use Illuminate\Console\Command;

class RunBackupCommand extends Command
{
    protected $signature = 'errorvault:run-backup
                            {--verbose-log : Print the backup manager log after the run}';

    protected $description = 'Poll the ErrorVault portal for a pending backup and run it if one is queued.';

    public function handle(): int
    {
        $manager = new BackupManager(config('errorvault'));

        if (!$manager->isEnabled()) {
            $this->error('ErrorVault backup is not enabled. Set ERRORVAULT_BACKUP_ENABLED=true and ensure ERRORVAULT_ENABLED, ERRORVAULT_API_TOKEN are configured.');
            return self::FAILURE;
        }

        $this->info('Polling ErrorVault for pending backups…');
        $result = $manager->pollAndRun();

        switch ($result['status']) {
            case 'idle':
                $this->info('✓ ' . $result['message']);
                break;
            case 'skipped':
                $this->warn('→ ' . $result['message']);
                break;
            case 'completed':
                $this->info('✓ Backup uploaded successfully' . (isset($result['parts']) ? " ({$result['parts']} parts, " . $this->formatSize($result['file_size'] ?? 0) . ')' : ''));
                break;
            case 'failed':
                $this->error('✗ Backup failed: ' . $result['message']);
                break;
        }

        if ($this->option('verbose-log')) {
            $this->newLine();
            $this->line('<fg=cyan>Backup log:</>');
            foreach ($manager->getLog() as $line) {
                $this->line('  ' . $line);
            }
        }

        return $result['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    protected function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
