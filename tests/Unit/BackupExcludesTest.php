<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Support\BackupExcludes;
use PHPUnit\Framework\TestCase;

class BackupExcludesTest extends TestCase
{
    public function testSnapshotKeepsUploadPathsByDefault(): void
    {
        $excludes = BackupExcludes::snapshot(
            includeVendor: false,
            excludeStorage: false,
            excludeUploads: false,
            baseExcludes: ['bootstrap/cache', 'public/uploads', 'storage/app/updater'],
            uploadsPaths: ['public/uploads'],
        );

        $this->assertNotContains('public/uploads', $excludes);
        $this->assertContains('vendor', $excludes);
        $this->assertContains('bootstrap/cache', $excludes);
    }

    public function testSnapshotCanExcludeUploadPathsWhenRequested(): void
    {
        $excludes = BackupExcludes::snapshot(
            includeVendor: true,
            excludeStorage: false,
            excludeUploads: true,
            baseExcludes: ['public/uploads'],
            uploadsPaths: ['public/uploads'],
        );

        $this->assertContains('public/uploads', $excludes);
        $this->assertNotContains('vendor', $excludes);
    }
    public function testSnapshotNeverRemovesImmutableExcludesEvenIfConfiguredAsUploads(): void
    {
        $excludes = BackupExcludes::snapshot(
            includeVendor: true,
            excludeStorage: false,
            excludeUploads: false,
            baseExcludes: ['.git', '.git/', 'storage/app/updater', 'storage/framework/down'],
            uploadsPaths: ['.git', 'storage/app/updater', 'storage/framework/down'],
        );

        $this->assertContains('.git', $excludes);
        $this->assertContains('storage/app/updater', $excludes);
        $this->assertContains('storage/framework/down', $excludes);
    }

}
