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

    public function testViewsNaoDependemDeRotasNovasEmCacheLegado(): void
    {
        $root = dirname(__DIR__, 2) . '/resources/views/';
        $listing = file_get_contents($root . 'sections/runs.blade.php');
        $details = file_get_contents($root . 'runs/show.blade.php');
        $dashboard = file_get_contents($root . 'dashboard.blade.php');
        $updates = file_get_contents($root . 'sections/updates.blade.php');

        self::assertIsString($listing);
        self::assertIsString($details);
        self::assertIsString($dashboard);
        self::assertIsString($updates);

        // O menu Execuções deve abrir mesmo quando o route:cache antigo não
        // contém os links adicionados numa versão posterior do updater.
        self::assertStringContainsString("Route::has('updater.runs.diagnostics.download')", $listing);
        self::assertStringContainsString("Route::has('updater.runs.show')", $listing);
        self::assertStringContainsString('Detalhes indisponíveis', $listing);
        self::assertStringContainsString("'download' => 'json'", $listing);
        self::assertStringContainsString("'download' => 'zip'", $listing);
        self::assertStringContainsString("Route::has('updater.runs.diagnostics.download')", $details);
        self::assertStringContainsString('Exportação temporariamente indisponível', $details);
        self::assertStringContainsString("'download' => 'json'", $details);
        self::assertStringContainsString("Route::has('updater.runs.show')", $dashboard);
        self::assertStringContainsString("Route::has('updater.runs.show')", $updates);

        $controller = file_get_contents(dirname(__DIR__, 2) . '/src/Http/Controllers/OperationsController.php');
        self::assertIsString($controller);
        self::assertStringContainsString("request()->query('download')", $controller);
        self::assertStringContainsString("in_array(\$format, ['json', 'zip'], true)", $controller);
        self::assertStringContainsString('return $this->downloadRunDiagnostics($id, $format)', $controller);
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
        self::assertStringNotContainsString('sanitize($status)', $dashboard);
        self::assertStringContainsString('context.erro || context.error', $js);
    }
}
