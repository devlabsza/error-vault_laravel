<?php

namespace ErrorVault\Laravel\Backup;

use RuntimeException;
use ZipArchive;

/**
 * Packages the Laravel project directory into a single zip, plus any
 * additional files the caller wants to include (typically the SQL dump).
 * Exclusions are matched as prefixes relative to the project base path.
 */
class ArchiveBuilder
{
    protected array $log = [];

    public function __construct(
        protected string $basePath,
        protected array $excludePaths = [],
        protected bool $includeStorageApp = false,
    ) {
    }

    /**
     * @param array<string,string> $extraFiles  source_path => archive_path pairs
     */
    public function build(string $targetZip, array $extraFiles = []): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The ZipArchive extension is required to build backups.');
        }

        $dir = dirname($targetZip);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory: {$dir}");
        }

        $zip = new ZipArchive();
        if ($zip->open($targetZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot create zip: {$targetZip}");
        }

        $excludePrefixes = $this->normaliseExcludes();
        $added = 0;
        $skipped = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->basePath, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $absolute = (string) $file;
            $relative = ltrim(substr($absolute, strlen($this->basePath)), '/\\');
            $relative = str_replace('\\', '/', $relative);

            if ($relative === '') {
                continue;
            }

            if ($this->shouldExclude($relative, $excludePrefixes)) {
                $skipped++;
                continue;
            }

            if ($file->isDir()) {
                $zip->addEmptyDir($relative);
                continue;
            }

            if (!$file->isReadable()) {
                $skipped++;
                continue;
            }

            $zip->addFile($absolute, $relative);
            $added++;
        }

        foreach ($extraFiles as $source => $archivePath) {
            if (is_file($source) && is_readable($source)) {
                $zip->addFile($source, ltrim($archivePath, '/'));
                $added++;
            }
        }

        $zip->close();

        $this->log("Archive built: added {$added} files, skipped {$skipped}, size " . $this->formatBytes((int) @filesize($targetZip)));

        return $targetZip;
    }

    public function getLog(): array
    {
        return $this->log;
    }

    protected function normaliseExcludes(): array
    {
        $prefixes = [];
        foreach ($this->excludePaths as $path) {
            $p = trim((string) $path);
            if ($p === '') {
                continue;
            }
            $prefixes[] = rtrim(str_replace('\\', '/', $p), '/');
        }

        if (!$this->includeStorageApp) {
            $prefixes[] = 'storage/app';
        }

        return array_values(array_unique($prefixes));
    }

    protected function shouldExclude(string $relativePath, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($relativePath === $prefix || str_starts_with($relativePath, $prefix . '/')) {
                return true;
            }
        }
        return false;
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
