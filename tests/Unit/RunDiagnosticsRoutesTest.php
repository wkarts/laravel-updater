<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class RunDiagnosticsRoutesTest extends TestCase
{
    public function testExportRoutesAreInsideProtectedUpdaterGroups(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2) . '/routes/updater.php');
        self::assertIsString($routes);
        self::assertSame(2, substr_count($routes, "->name('updater.runs.diagnostics.download')"));
        self::assertStringContainsString("->whereIn('format', ['json', 'zip'])", $routes);
        self::assertStringContainsString('downloadRunDiagnostics', $routes);
    }

    public function testDashboardAndExecutionPageSurfaceFailureAndDownload(): void
    {
        $root = dirname(__DIR__, 2);
        $runView = file_get_contents($root . '/resources/views/runs/show.blade.php');
        $dashboard = file_get_contents($root . '/resources/views/dashboard.blade.php');
        $js = file_get_contents($root . '/resources/assets/updater.js');
        self::assertIsString($runView);
        self::assertIsString($dashboard);
        self::assertIsString($js);
        self::assertStringContainsString('Motivo da falha', $runView);
        self::assertStringContainsString('Linha do tempo das etapas', $runView);
        self::assertStringContainsString('Baixar ZIP', $runView);
        self::assertStringContainsString('Baixar JSON', $runView);
        self::assertStringContainsString('Última atualização falhou', $dashboard);
        self::assertStringContainsString('context.erro || context.error', $js);
    }
}
