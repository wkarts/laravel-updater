<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Support\GzipStream;
use PHPUnit\Framework\TestCase;

class GzipStreamTest extends TestCase
{
    public function testCompactaEDescompactaArquivoPorStream(): void
    {
        $base = sys_get_temp_dir() . '/updater-gzip-' . uniqid('', true);
        $source = $base . '.sql';
        $gzip = $base . '.sql.gz';
        $restored = $base . '.restored.sql';

        $handle = fopen($source, 'wb');
        $this->assertIsResource($handle);

        $chunk = str_repeat("INSERT INTO teste VALUES (1, 'abc');\n", 25000);
        for ($i = 0; $i < 12; $i++) {
            fwrite($handle, $chunk);
        }
        fclose($handle);

        GzipStream::compressFile($source, $gzip, 6);

        $this->assertFileExists($gzip);
        $this->assertGreaterThan(0, filesize($gzip));

        GzipStream::decompressFile($gzip, $restored);

        $this->assertFileExists($restored);
        $this->assertSame(hash_file('sha256', $source), hash_file('sha256', $restored));

        @unlink($source);
        @unlink($gzip);
        @unlink($restored);
    }
}
