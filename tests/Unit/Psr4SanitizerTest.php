<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Support\Psr4Sanitizer;
use PHPUnit\Framework\TestCase;

final class Psr4SanitizerTest extends TestCase
{
    public function testQuarantineSuspiciousFileByPattern(): void
    {
        $root = sys_get_temp_dir() . '/updater-psr4-' . uniqid('', true);
        mkdir($root . '/app/Services', 0777, true);
        mkdir($root . '/storage', 0777, true);

        file_put_contents($root . '/app/Services/Foo(1).php', "<?php\nclass Foo {}\n");

        $sanitizer = new Psr4Sanitizer();
        $report = $sanitizer->run($root, [
            'mode' => 'quarantine',
            'paths' => ['app'],
            'quarantine_path' => 'storage/app/updater/quarantine/psr4',
            'filename_patterns' => ['/\(\d+\)\.php$/i'],
            'directory_name_patterns' => [],
        ]);

        $this->assertSame(1, $report['checked_files']);
        $this->assertCount(1, $report['quarantined_files']);
        $this->assertFileDoesNotExist($root . '/app/Services/Foo(1).php');
    }
}
