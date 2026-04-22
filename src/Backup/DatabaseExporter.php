<?php

namespace ErrorVault\Laravel\Backup;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Dumps the configured MySQL database to a .sql file, preferring mysqldump
 * (10-100x faster on large databases) and falling back to a PHP loop when
 * mysqldump isn't on the host.
 */
class DatabaseExporter
{
    protected array $log = [];

    public function __construct(protected string $connection = 'mysql')
    {
    }

    /**
     * @return string Absolute path to the written .sql file.
     */
    public function export(string $targetPath): string
    {
        $config = config("database.connections.{$this->connection}");

        if (!is_array($config) || ($config['driver'] ?? null) !== 'mysql') {
            throw new RuntimeException("DatabaseExporter only supports mysql, got: " . ($config['driver'] ?? 'unknown'));
        }

        $dir = dirname($targetPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory: {$dir}");
        }

        if ($this->tryMysqldump($config, $targetPath)) {
            return $targetPath;
        }

        $this->log('mysqldump unavailable, using PHP fallback');
        $this->phpExport($config, $targetPath);

        return $targetPath;
    }

    public function getLog(): array
    {
        return $this->log;
    }

    protected function log(string $message): void
    {
        $this->log[] = $message;
    }

    protected function tryMysqldump(array $config, string $targetPath): bool
    {
        $binary = $this->findMysqldump();
        if (!$binary) {
            return false;
        }

        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 3306;
        $user = $config['username'] ?? '';
        $pass = $config['password'] ?? '';
        $db = $config['database'] ?? '';

        if ($db === '') {
            $this->log('No database name in config; cannot dump');
            return false;
        }

        // Use --defaults-extra-file for credentials instead of command-line
        // to avoid leaking the password to `ps`.
        $credsFile = $targetPath . '.mycnf';
        file_put_contents($credsFile, "[client]\nuser={$user}\npassword=\"{$pass}\"\nhost={$host}\nport={$port}\n");
        @chmod($credsFile, 0600);

        $cmd = sprintf(
            '%s --defaults-extra-file=%s --single-transaction --quick --no-tablespaces --routines --triggers --skip-lock-tables %s > %s 2>&1',
            escapeshellarg($binary),
            escapeshellarg($credsFile),
            escapeshellarg($db),
            escapeshellarg($targetPath)
        );

        $output = [];
        $rc = 0;
        exec($cmd, $output, $rc);
        @unlink($credsFile);

        if ($rc === 0 && file_exists($targetPath) && filesize($targetPath) > 0) {
            $this->log('mysqldump succeeded: ' . $this->formatBytes(filesize($targetPath)));
            return true;
        }

        $this->log('mysqldump failed (rc=' . $rc . '): ' . implode("\n", $output));
        if (file_exists($targetPath) && filesize($targetPath) === 0) {
            @unlink($targetPath);
        }
        return false;
    }

    protected function findMysqldump(): ?string
    {
        $candidates = [
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            '/usr/local/mysql/bin/mysqldump',
            '/opt/homebrew/bin/mysqldump',
        ];

        foreach ($candidates as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        $which = @shell_exec('command -v mysqldump 2>/dev/null');
        if (is_string($which) && trim($which) !== '') {
            $path = trim($which);
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Pure-PHP export. Slower than mysqldump and memory-sensitive on large
     * tables, but works on hosts that have `exec`/`shell_exec` disabled or
     * no mysqldump binary.
     */
    protected function phpExport(array $config, string $targetPath): void
    {
        $fp = fopen($targetPath, 'w');
        if (!$fp) {
            throw new RuntimeException("Cannot open {$targetPath} for writing");
        }

        try {
            fwrite($fp, "-- ErrorVault Laravel backup\n");
            fwrite($fp, "-- Generated: " . date('c') . "\n");
            fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            $tables = collect(DB::connection($this->connection)->select('SHOW TABLES'))
                ->map(fn ($row) => reset($row))
                ->all();

            foreach ($tables as $table) {
                $this->dumpTable($fp, $table);
            }

            fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($fp);
        }

        $this->log('PHP fallback export wrote ' . $this->formatBytes(filesize($targetPath)));
    }

    protected function dumpTable($fp, string $table): void
    {
        $conn = DB::connection($this->connection);

        $create = $conn->selectOne("SHOW CREATE TABLE `{$table}`");
        $createSql = $create->{'Create Table'} ?? $create->{'Create View'} ?? null;
        if (!$createSql) {
            return;
        }

        fwrite($fp, "DROP TABLE IF EXISTS `{$table}`;\n");
        fwrite($fp, $createSql . ";\n\n");

        // Stream rows in chunks of 500 to avoid holding a whole table in memory.
        $offset = 0;
        $chunk = 500;
        while (true) {
            $rows = $conn->select("SELECT * FROM `{$table}` LIMIT {$chunk} OFFSET {$offset}");
            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $values = array_map(
                    fn ($v) => $v === null ? 'NULL' : ("'" . str_replace(["\\", "'", "\n", "\r"], ["\\\\", "\\'", "\\n", "\\r"], (string) $v) . "'"),
                    (array) $row
                );
                $cols = '`' . implode('`,`', array_keys((array) $row)) . '`';
                fwrite($fp, "INSERT INTO `{$table}` ({$cols}) VALUES (" . implode(',', $values) . ");\n");
            }

            $offset += $chunk;
            if (count($rows) < $chunk) {
                break;
            }
        }

        fwrite($fp, "\n");
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
