<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class UpdateStatusFailurePresentationTest extends TestCase
{
    public function testUnavailableRemoteStatusIsNotDisplayedAsNoUpdates(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root . '/src/Http/Controllers/ManagerController.php');
        $view = file_get_contents($root . '/resources/views/sections/updates.blade.php');

        self::assertIsString($controller);
        self::assertIsString($view);
        self::assertStringContainsString("'status_unavailable' => true", $controller);
        self::assertStringContainsString("['git', 'rev-parse', 'HEAD']", $controller);
        self::assertStringContainsString("status_unavailable", $view);
        self::assertStringContainsString('INDETERMINADO', $view);
    }
}
