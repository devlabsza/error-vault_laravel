<?php

namespace ErrorVault\Laravel\Backup;

use ErrorVault\Laravel\ErrorVault;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Orchestrates a full backup run: poll the portal, dump the database,
 * zip the project, upload chunked, report success/failure.
 *
 * Run points:
 *  - errorvault:run-backup artisan command (manual / scheduled)
 */
class BackupManager
{
    protected array $log = [];

    public function __construct(protected array $config)
    {
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false)
            && (bool) ($this->config['backup']['enabled'] ?? false)
            && !empty($this->config['api_endpoint'])
            && !empty($this->config['api_token']);
    }

    /**
     * @return array{status: string, message: string, backup_id?: int|string}
     */
    public function pollAndRun(): array
    {
        if (!$this->isEnabled()) {
            return ['status' => 'skipped', 'message' => 'Backup is not enabled.'];
        }

        // Stop concurrent runs — backups are expensive.
        $lock = Cache::lock('errorvault:backup', 30 * 60);
        if (!$lock->get()) {
            return ['status' => 'skipped', 'message' => 'Another backup is already running.'];
        }

        try {
            $client = new UploadClient(
                (string) $this->config['api_endpoint'],
                (string) $this->config['api_token']
            );

            $poll = $client->pollPending();
            if (!$poll['has_pending_backup'] || empty($poll['backup']['id'])) {
                return ['status' => 'idle', 'message' => 'No pending backup.'];
            }

            $backup = $poll['backup'];
            $backupId = (int) $backup['id'];
            $this->log("Pending backup #{$backupId} — starting");

            $result = $this->run($backupId, $backup, $client);
            $result['backup_id'] = $backupId;
            return $result;
        } catch (Throwable $e) {
            Log::error('[ErrorVault] Backup poll/run failed: ' . $e->getMessage());
            return ['status' => 'failed', 'message' => $e->getMessage()];
        } finally {
            $lock->release();
        }
    }

    /**
     * Run a specific pending backup through the full pipeline.
     */
    public function run(int $backupId, array $backup, UploadClient $client): array
    {
        $sqlPath = null;
        $zipPath = null;
        $uploadId = null;
        $scope = ($backup['scope'] ?? 'full') === 'database' ? 'database' : 'full';

        try {
            $tmp = $this->privateTempPath((string) ($this->config['backup']['tmp_path'] ?? sys_get_temp_dir()));
            if (!is_dir($tmp)) {
                if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) {
                    throw new RuntimeException("Cannot create tmp path: {$tmp}");
                }
                @chmod($tmp, 0700);
            }
            if (!is_writable($tmp)) {
                throw new RuntimeException("Backup tmp path is not writable: {$tmp}");
            }
            $this->cleanupStaleFiles($tmp);

            $stem = $tmp . '/ev-backup-' . $backupId . '-' . bin2hex(random_bytes(10));
            $sqlPath = $stem . '.sql';
            $zipPath = $stem . '.zip';

            // 1. Database dump.
            $this->log('Exporting database');
            $exporter = new DatabaseExporter();
            $oldUmask = umask(0077);
            try {
                $exporter->export($sqlPath);
            } finally {
                umask($oldUmask);
            }
            @chmod($sqlPath, 0600);
            foreach ($exporter->getLog() as $line) {
                $this->log('  ' . $line);
            }

            // 2. Archive.
            $this->log('Building archive');
            $builder = new ArchiveBuilder(
                base_path(),
                array_merge((array) ($this->config['backup']['exclude_paths'] ?? []), [$tmp]),
                (bool) ($this->config['backup']['include_storage'] ?? false),
            );
            $builder->build($zipPath, [$sqlPath => 'database.sql'], $scope === 'full');
            foreach ($builder->getLog() as $line) {
                $this->log('  ' . $line);
            }

            $fileSize = (int) filesize($zipPath);
            $checksum = hash_file('sha256', $zipPath);
            $this->log('Archive size: ' . $this->formatBytes($fileSize) . ', sha256: ' . substr($checksum, 0, 12) . '…');

            $metadata = [
                'file_size' => $fileSize,
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'plugin_version' => ErrorVault::VERSION,
                'include_storage' => $scope === 'full' && (bool) ($this->config['backup']['include_storage'] ?? false),
                'scope' => $scope,
            ];

            // 3. Initiate.
            $initiated = $client->initiate($backupId, $checksum, $metadata);
            $uploadId = (string) $initiated['upload_id'];
            $this->log("Initiated upload: {$uploadId}");

            // 4. Upload parts.
            $chunkSize = max(1, (int) ($this->config['backup']['chunk_size_mb'] ?? 5)) * 1024 * 1024;
            $parts = $this->uploadChunks($client, $backupId, $uploadId, $zipPath, $chunkSize);

            // 5. Complete.
            $client->complete($backupId, $uploadId, $parts);
            $this->log('Upload completed');

            return [
                'status' => 'completed',
                'message' => 'Backup uploaded successfully.',
                'file_size' => $fileSize,
                'parts' => count($parts),
            ];
        } catch (Throwable $e) {
            $this->log('Backup failed: ' . $e->getMessage());
            if ($uploadId !== null) {
                $client->abort($backupId, $uploadId);
            }
            $client->fail($backupId, $e->getMessage());
            return ['status' => 'failed', 'message' => $e->getMessage()];
        } finally {
            if ($sqlPath !== null) {
                @unlink($sqlPath);
            }
            if ($zipPath !== null) {
                @unlink($zipPath);
            }
        }
    }

    protected function uploadChunks(UploadClient $client, int $backupId, string $uploadId, string $zipPath, int $chunkSize): array
    {
        $fp = fopen($zipPath, 'rb');
        if (!$fp) {
            throw new RuntimeException("Cannot open archive: {$zipPath}");
        }

        $parts = [];
        $partNumber = 1;

        try {
            while (!feof($fp)) {
                $chunk = fread($fp, $chunkSize);
                if ($chunk === false || $chunk === '') {
                    break;
                }

                $result = $client->uploadPart($backupId, $uploadId, $partNumber, $chunk);
                $parts[] = [
                    'part_number' => $partNumber,
                    'etag' => $result['etag'],
                ];

                $this->log("Uploaded part {$partNumber} (" . $this->formatBytes(strlen($chunk)) . ')');
                $partNumber++;
            }
        } finally {
            fclose($fp);
        }

        if (empty($parts)) {
            throw new RuntimeException('No data read from archive during chunked upload.');
        }

        return $parts;
    }

    /**
     * Resolve the configured working folder and refuse locations commonly
     * reachable from the web. Database dumps must never be written under public/.
     */
    protected function privateTempPath(string $path): string
    {
        $path = $this->normalisePath($path);
        if ($path === '/' || preg_match('#^[A-Za-z]:/?$#', $path)) {
            throw new RuntimeException('ERRORVAULT_BACKUP_TMP_PATH must be a dedicated private directory, not a filesystem root.');
        }
        $publicPaths = [
            function_exists('public_path') ? public_path() : base_path('public'),
            function_exists('storage_path') ? storage_path('app/public') : base_path('storage/app/public'),
        ];

        foreach ($publicPaths as $public) {
            $public = $this->normalisePath((string) $public);
            if ($path === $public || str_starts_with($path, $public . '/')) {
                throw new RuntimeException('ERRORVAULT_BACKUP_TMP_PATH must not be inside a public web directory.');
            }
        }

        return $path;
    }

    protected function normalisePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '') {
            throw new RuntimeException('Backup tmp path is empty.');
        }
        if (!str_starts_with($path, '/') && !preg_match('#^[A-Za-z]:/#', $path)) {
            $path = base_path($path);
        }

        $prefix = str_starts_with($path, '/') ? '/' : '';
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }
        $path = $prefix . implode('/', $parts);
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        $resolved = realpath($path);
        if ($resolved !== false) {
            return rtrim(str_replace('\\', '/', $resolved), '/');
        }
        $parent = realpath(dirname($path));
        if ($parent !== false) {
            return rtrim(str_replace('\\', '/', $parent), '/') . '/' . basename($path);
        }
        return $path;
    }

    /** Remove only old files created by this package after an interrupted run. */
    protected function cleanupStaleFiles(string $tmp): void
    {
        foreach ((array) glob($tmp . '/ev-backup-*') as $file) {
            if ((is_file($file) || is_link($file)) && time() - (int) @filemtime($file) >= 6 * 3600) {
                @unlink($file);
            }
        }
    }

    public function getLog(): array
    {
        return $this->log;
    }

    protected function log(string $message): void
    {
        $this->log[] = $message;
    }

    protected function formatBytes(int $bytes): string
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
