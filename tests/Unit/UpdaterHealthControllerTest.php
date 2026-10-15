<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Http\Controllers\UpdaterHealthController;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UpdaterHealthControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function testReadinessMinimaRetorna200SemExporDados(): void
    {
        $this->configureHealthcheck(false);
        $response = (new UpdaterHealthController())();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['updater_health' => true, 'status' => 'ok'], $response->getData(true));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function testFalhaDoBancoOptInRetorna503SemExporDetalhes(): void
    {
        $container = $this->configureHealthcheck(true);
        $container->instance('db', new class {
            public function connection(): object
            {
                return new class {
                    public function select(string $sql): array
                    {
                        throw new RuntimeException('confidential database password');
                    }
                };
            }
        });

        $response = (new UpdaterHealthController())();

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(['updater_health' => true, 'status' => 'unhealthy'], $response->getData(true));
        self::assertStringNotContainsString('confidential', (string) $response->getContent());
    }

    public function testRotaDeReadinessNaoDependeDaInterfaceHabilitada(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2) . '/routes/updater.php');

        self::assertIsString($routes);
        self::assertStringContainsString("Route::get(\$healthPrefix . '/health', UpdaterHealthController::class)", $routes);
        self::assertStringContainsString("config('updater.healthcheck.enabled'", $routes);
    }

    private function configureHealthcheck(bool $databaseCheck): Container
    {
        $container = new Container();
        $container->instance('config', new Repository([
            'updater' => [
                'healthcheck' => ['check_database' => $databaseCheck],
            ],
        ]));
        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        return $container;
    }
}
