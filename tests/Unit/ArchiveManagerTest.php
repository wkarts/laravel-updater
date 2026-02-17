<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Support\ArchiveManager;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class ArchiveManagerTest extends TestCase
{
    public function testCreateZipFromDirectoryNaoVazaPrefixoParcialNoRelativePath(): void
    {
        $base = sys_get_temp_dir() . '/updater_archive_test_' . uniqid();
        $source = $base . '/app';
        $snapshotDir = $base . '/snapshots';
        @mkdir($source, 0777, true);
        @mkdir($snapshotDir, 0777, true);

        file_put_contents($source . '/index.php', '<?php echo "ok";');
        file_put_contents($source . '/composer.json', '{"name":"demo/test"}');

        $zipPath = $snapshotDir . '/snapshot.zip';

        $manager = new ArchiveManager();
        $manager->createZipFromDirectory($source, $zipPath, []);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);

        $this->assertNotFalse($zip->locateName('index.php'));
        $this->assertNotFalse($zip->locateName('composer.json'));
        $this->assertFalse($zip->locateName('shots/index.php'));

        $zip->close();

        @unlink($zipPath);
        @unlink($source . '/index.php');
        @unlink($source . '/composer.json');
        @rmdir($source);
        @rmdir($snapshotDir);
        @rmdir($base);
    }
}
