<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

final class RunViewsCachedRoutesTest extends TestCase
{
    private string $viewsPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->viewsPath = sys_get_temp_dir() . '/updater-view-compat-' . bin2hex(random_bytes(7));
        mkdir($this->viewsPath, 0775, true);
        // Isola o layout, não a compilação Blade nem a geração real de URLs.
        file_put_contents($this->viewsPath . '/layout.blade.php', "@yield('content')\n");
        $this->app['view']->getFinder()->replaceNamespace('laravel-updater', [
            $this->viewsPath, dirname(__DIR__, 2) . '/resources/views',
        ]);
        $this->app['router']->setRoutes(new RouteCollection());
        Route::get('/_updater/runs/{id}', static fn () => 'test')->name('updater.runs.show');
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    protected function tearDown(): void
    {
        if (isset($this->viewsPath)) {
            (new Filesystem())->deleteDirectory($this->viewsPath);
        }
        parent::tearDown();
    }

    public function testListingRendersWithoutNewDownloadRoute(): void
    {
        self::assertFalse(Route::has('updater.runs.diagnostics.download'));
        $html = $this->app['view']->make('laravel-updater::sections.runs', ['runs' => [$this->runFixture()]])->render();
        self::assertStringContainsString('Histórico completo', $html);
        self::assertStringContainsString('/_updater/runs/186', $html);
        self::assertStringContainsString('download=json', $html);
        self::assertStringContainsString('download=zip', $html);
    }

    public function testDetailsRenderOriginalErrorAndFallbackDownloads(): void
    {
        self::assertFalse(Route::has('updater.runs.diagnostics.download'));
        $html = $this->app['view']->make('laravel-updater::runs.show', [
            'run' => $this->runFixture(),
            'diagnostics' => [
                'summary' => ['first_failed_step' => 'git_update', 'event_count' => 1],
                'error' => ['message' => 'would clobber existing tag'],
                'events' => [[
                    'timestamp' => '2026-10-15T17:37:21-03:00', 'level' => 'error',
                    'step' => 'git_update', 'message' => 'Falha em etapa da atualização.',
                    'context' => ['erro' => 'would clobber existing tag'],
                ]],
            ],
        ])->render();
        self::assertStringContainsString('would clobber existing tag', $html);
        self::assertStringContainsString('git_update', $html);
        self::assertStringContainsString('download=json', $html);
        self::assertStringContainsString('download=zip', $html);
    }

    private function runFixture(): array
    {
        return [
            'id' => 186, 'status' => 'failed', 'started_at' => '2026-10-15T17:36:28-03:00',
            'finished_at' => '2026-10-15T17:37:21-03:00', 'revision_before' => 'before',
            'revision_after' => 'after',
        ];
    }
}
