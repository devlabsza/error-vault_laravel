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
     * @param array<string,string> $extraFiles source_path => archive_path pairs
     */
    public function build(string $targetZip, array $extraFiles = [], bool $includeProject = true): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The ZipArchive extension is required to build backups.');
        }

        $base = realpath($this->basePath);
        if ($base === false || !is_dir($base)) {
            throw new RuntimeException("Project base path does not exist: {$this->basePath}");
        }
        $base = $this->normaliseAbsolute($base);
        $targetZip = $this->normaliseAbsolute($targetZip);

        $dir = dirname($targetZip);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new RuntimeException("Cannot create directory: {$dir}");
            }
            @chmod($dir, 0700);
        }

        $zip = new ZipArchive();
        $oldUmask = umask(0077);
        try {
            $opened = $zip->open($targetZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        } finally {
            umask($oldUmask);
        }
        if ($opened !== true) {
            throw new RuntimeException("Cannot create zip: {$targetZip}");
        }
        @chmod($targetZip, 0600);

        $excludePrefixes = $this->normaliseExcludes($base);
        $excludeFiles = [$targetZip => true];
        foreach (array_keys($extraFiles) as $source) {
            $excludeFiles[$this->normaliseAbsolute((string) $source)] = true;
        }

        $added = 0;
        $skipped = 0;

        try {
            if ($includeProject) {
                // Filtering here, before RecursiveIteratorIterator descends, is
                // essential: merely continuing on an excluded directory still
                // walks and archives every child under it.
                $directory = new \RecursiveDirectoryIterator(
                    $base,
                    \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS
                );
                $filter = new \RecursiveCallbackFilterIterator(
                    $directory,
                    function (\SplFileInfo $file) use ($base, $excludePrefixes, $excludeFiles, &$skipped): bool {
                        $absolute = $this->normaliseAbsolute($file->getPathname());
                        $relative = $this->relativeToBase($absolute, $base);

                        if ($file->isLink()
                            || isset($excludeFiles[$absolute])
                            || $this->shouldExclude($relative, $excludePrefixes)
                            || !$file->isReadable()) {
                            $skipped++;
                            return false;
                        }

                        return true;
                    }
                );
                $iterator = new \RecursiveIteratorIterator(
                    $filter,
                    \RecursiveIteratorIterator::SELF_FIRST,
                    \RecursiveIteratorIterator::CATCH_GET_CHILD
                );

                foreach ($iterator as $file) {
                    $absolute = $this->normaliseAbsolute($file->getPathname());
                    $relative = $this->relativeToBase($absolute, $base);
                    if ($relative === '') {
                        continue;
                    }

                    if ($file->isDir()) {
                        if (!$zip->addEmptyDir($relative)) {
                            throw new RuntimeException("Cannot add directory to archive: {$relative}");
                        }
                        continue;
                    }

                    if (!$zip->addFile($absolute, $relative)) {
                        throw new RuntimeException("Cannot add file to archive: {$relative}");
                    }
                    $added++;
                }
            }

            foreach ($extraFiles as $source => $archivePath) {
                $source = $this->normaliseAbsolute((string) $source);
                $archivePath = $this->safeArchivePath((string) $archivePath);
                if (!is_file($source) || is_link($source) || !is_readable($source)) {
                    throw new RuntimeException("Cannot read required backup file: {$source}");
                }
                if (!$zip->addFile($source, $archivePath)) {
                    throw new RuntimeException("Cannot add required backup file: {$archivePath}");
                }
                $added++;
            }
        } finally {
            $zip->close();
        }

        $this->log("Archive built: added {$added} files, skipped {$skipped}, size " . $this->formatBytes((int) @filesize($targetZip)));

        return $targetZip;
    }

    public function getLog(): array
    {
        return $this->log;
    }

    protected function normaliseExcludes(string $base): array
    {
        $prefixes = [];
        foreach ($this->excludePaths as $path) {
            $p = trim(str_replace('\\', '/', (string) $path));
            if ($p === '') {
                continue;
            }
            if ($this->isAbsolute($p)) {
                $p = $this->relativeToBase($this->normaliseAbsolute($p), $base);
            }
            $p = trim($p, '/');
            if ($p !== '' && $p !== '..' && !str_starts_with($p, '../')) {
                $prefixes[] = $p;
            }
        }

        if (!$this->includeStorageApp) {
            $prefixes[] = 'storage/app';
        }

        return array_values(array_unique($prefixes));
    }

    protected function normaliseAbsolute(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (!$this->isAbsolute($path)) {
            $path = getcwd() . '/' . $path;
        }

        $prefix = str_starts_with($path, '/') ? '/' : '';
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        $path = $prefix . implode('/', $parts);

        // Resolve aliases such as macOS /var -> /private/var. For a target
        // that does not exist yet, resolving its existing parent is enough.
        $resolved = realpath($path);
        if ($resolved !== false) {
            return str_replace('\\', '/', $resolved);
        }
        $parent = realpath(dirname($path));
        if ($parent !== false) {
            return rtrim(str_replace('\\', '/', $parent), '/') . '/' . basename($path);
        }

        return $path;
    }

    protected function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || (bool) preg_match('#^[A-Za-z]:/#', $path);
    }

    protected function relativeToBase(string $absolute, string $base): string
    {
        if ($absolute === $base) {
            return '';
        }
        if (!str_starts_with($absolute, $base . '/')) {
            return '../';
        }
        return substr($absolute, strlen($base) + 1);
    }

    protected function safeArchivePath(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if ($path === '' || preg_match('#(?:^|/)\.\.(?:/|$)#', $path) || preg_match('#^[A-Za-z]:#', $path)) {
            throw new RuntimeException("Unsafe archive path: {$path}");
        }
        return $path;
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
