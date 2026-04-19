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
}
