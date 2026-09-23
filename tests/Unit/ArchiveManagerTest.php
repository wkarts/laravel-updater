<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Support\ArchiveManager;
use PHPUnit\Framework\TestCase;

final class ArchiveManagerTest extends TestCase
{
    public function testCreateZipFromFilesGeraArquivoFinal(): void
    {
        $root = sys_get_temp_dir() . '/updater-archive-test-' . uniqid('', true);
        @mkdir($root, 0777, true);

        $source = $root . '/input.txt';
        file_put_contents($source, 'conteudo');
        $target = $root . '/out/archive.zip';

        $manager = new ArchiveManager();
        $manager->createZipFromFiles([$source => 'input.txt'], $target);

        $this->assertFileExists($target);
        $this->assertGreaterThan(0, (int) filesize($target));
    }
    public function testCreateZipFromDirectoryRetriesWhenCloseThrowsWarningAsException(): void
    {
        $root = sys_get_temp_dir() . '/updater-archive-retry-test-' . uniqid('', true);
        $sourceDir = $root . '/source';
        @mkdir($sourceDir, 0777, true);
        file_put_contents($sourceDir . '/input.txt', 'conteudo-estavel');

        $targetBase = $root . '/out/archive';

        $manager = new class extends ArchiveManager {
            public int $closeAttempts = 0;

            protected function closeZipArchive(\ZipArchive $zip): bool
            {
                $this->closeAttempts++;

                if ($this->closeAttempts === 1) {
                    throw new \ErrorException("ZipArchive::close(): Can't open file: No such file or directory");
                }

                return parent::closeZipArchive($zip);
            }
        };

        $target = $manager->createArchiveFromDirectory($sourceDir, $targetBase, 'zip');

        $this->assertSame(2, $manager->closeAttempts);
        $this->assertFileExists($target);
        $this->assertGreaterThan(0, (int) filesize($target));

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($target) === true);
        $this->assertSame('conteudo-estavel', $zip->getFromName('input.txt'));
        $zip->close();
    }

}
