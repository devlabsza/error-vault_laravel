<?php

namespace ErrorVault\Laravel\Tests;

use ErrorVault\Laravel\Backup\ArchiveBuilder;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class ArchiveBuilderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is not installed.');
        }

        $this->root = sys_get_temp_dir().'/errorvault-archive-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/vendor/package', 0700, true);
        mkdir($this->root.'/node_modules/package', 0700, true);
        mkdir($this->root.'/storage/app/private', 0700, true);
        mkdir($this->root.'/app', 0700, true);
        mkdir($this->root.'/work', 0700, true);
        file_put_contents($this->root.'/vendor/package/secret.php', 'vendor');
        file_put_contents($this->root.'/node_modules/package/module.js', 'node');
        file_put_contents($this->root.'/storage/app/private/data.txt', 'private');
        file_put_contents($this->root.'/app/Example.php', '<?php');
        file_put_contents($this->root.'/database.sql', 'SELECT 1;');
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            $this->remove($this->root);
        }
        parent::tearDown();
    }

    public function test_excluded_directories_are_pruned_and_work_files_and_symlinks_are_skipped(): void
    {
        $outside = $this->root.'-outside.txt';
        file_put_contents($outside, 'outside secret');
        $linked = function_exists('symlink') && @symlink($outside, $this->root.'/app/outside.txt');

        try {
            $zipPath = $this->root.'/work/backup.zip';
            $builder = new ArchiveBuilder($this->root, ['vendor', 'node_modules', 'work'], false);
            $builder->build($zipPath, [$this->root.'/database.sql' => 'database.sql']);

            $entries = $this->entries($zipPath);
            $this->assertContains('app/Example.php', $entries);
            $this->assertContains('database.sql', $entries);
            $this->assertNotContains('vendor/package/secret.php', $entries);
            $this->assertNotContains('node_modules/package/module.js', $entries);
            $this->assertNotContains('storage/app/private/data.txt', $entries);
            $this->assertNotContains('db.sql', $entries);
            $this->assertNotContains('work/backup.zip', $entries);
            if ($linked) {
                $this->assertNotContains('app/outside.txt', $entries);
            }
        } finally {
            @unlink($outside);
        }
    }

    public function test_database_scope_contains_only_the_explicit_dump(): void
    {
        $zipPath = $this->root.'/work/database.zip';
        $builder = new ArchiveBuilder($this->root, [], true);
        $builder->build($zipPath, [$this->root.'/database.sql' => 'database.sql'], false);

        $this->assertSame(['database.sql'], $this->entries($zipPath));
    }

    /** @return string[] */
    private function entries(string $path): array
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->getNameIndex($i);
        }
        $zip->close();
        sort($entries);
        return $entries;
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->remove($path.'/'.$entry);
        }
        @rmdir($path);
    }
}
