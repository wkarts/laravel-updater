<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Exceptions\UpdaterException;
use Argws\LaravelUpdater\Pipeline\Steps\HealthCheckStep;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

final class HealthCheckStepTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $container->instance('config', new Repository([
            'app' => ['url' => 'https://app.example.test'],
            'updater' => ['ui' => ['prefix' => '_updater']],
        ]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    public function testAppRootDuranteMaintenanceUsaRotaInternaSemLiberarMaintenance(): void
    {
        $step = $this->stub([
            'status' => 200,
            'body' => '{"updater_health":true,"status":"ok"}',
            'duration_ms' => 15,
        ]);

        $context = ['maintenance' => true, 'lock' => true];
        $step->handle($context);

        self::assertSame('https://app.example.test/_updater/health', $step->requests[0]['url']);
        self::assertTrue($context['healthcheck']['healthy']);
        self::assertSame('updater_readiness', $context['healthcheck']['mode']);
        self::assertSame(200, $context['healthcheck']['http_status']);
        self::assertTrue($context['maintenance']);
        self::assertTrue($context['lock']);
    }

    public function testUrlPersonalizadaEVerificadaComoAntes(): void
    {
        $step = $this->stub([
            'status' => 200,
            'body' => 'ok',
            'duration_ms' => 2,
        ], 'https://app.example.test/status/ready');

        $context = ['maintenance' => true];
        $step->handle($context);

        self::assertSame('https://app.example.test/status/ready', $step->requests[0]['url']);
        self::assertSame('configured_url', $context['healthcheck']['mode']);
        self::assertTrue($context['healthcheck']['healthy']);
    }

    public function testSemMaintenanceMantemHealthcheckLegado(): void
    {
        $step = $this->stub(['status' => 200, 'body' => '<html>ok</html>', 'duration_ms' => 4]);
        $context = ['maintenance' => false];
        $step->handle($context);

        self::assertSame('https://app.example.test', $step->requests[0]['url']);
        self::assertSame('configured_url', $context['healthcheck']['mode']);
    }

    public function testHttp503AindaEUmErroRealDeSaude(): void
    {
        $step = $this->stub([
            'status' => 503,
            'body' => 'unavailable',
            'duration_ms' => 10,
        ]);
        $context = ['maintenance' => true];

        $this->expectException(UpdaterException::class);
        $this->expectExceptionMessage('HTTP 503');

        try {
            $step->handle($context);
        } finally {
            self::assertSame(503, $context['healthcheck']['http_status']);
            self::assertFalse($context['healthcheck']['healthy']);
        }
    }

    public function testHttp200ComHtmlNoEndpointInternoNaoEConsideradoSaudavel(): void
    {
        $step = $this->stub([
            'status' => 200,
            'body' => '<html>Login</html>',
            'duration_ms' => 3,
        ]);
        $context = ['maintenance' => true];

        $this->expectException(UpdaterException::class);
        $this->expectExceptionMessage('resposta de saúde inválida');

        $step->handle($context);
    }

    public function testFalhaDeConexaoTemDiagnosticoSemExporResposta(): void
    {
        $step = $this->stub([
            'status' => 0,
            'body' => false,
            'duration_ms' => 5,
        ]);
        $context = ['maintenance' => true];

        $this->expectException(UpdaterException::class);
        $this->expectExceptionMessage('HTTP sem resposta');

        $step->handle($context);
    }

    public function testSkipLocalhostContinuaOpcional(): void
    {
        $step = $this->stub([
            'status' => 200,
            'body' => 'ok',
            'duration_ms' => 1,
        ], 'http://localhost/health');

        $context = ['maintenance' => true];
        $step->handle($context);

        self::assertSame([], $step->requests);
        self::assertSame('localhost_skipped', $context['healthcheck_warning'][0]['reason']);
    }

    /** @return HealthCheckStep&object */
    private function stub(array $response, string $url = 'https://app.example.test'): HealthCheckStep
    {
        return new class(['enabled' => true, 'url' => $url, 'timeout' => 5], $response) extends HealthCheckStep {
            public array $requests = [];

            public function __construct(array $config, private readonly array $fakeResponse)
            {
                parent::__construct($config);
            }

            protected function request(string $url, int $timeout): array
            {
                $this->requests[] = ['url' => $url, 'timeout' => $timeout];

                return $this->fakeResponse;
            }
        };
    }
}
