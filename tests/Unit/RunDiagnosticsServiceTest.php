<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Support\RunDiagnosticsService;
use Argws\LaravelUpdater\Support\StateStore;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class RunDiagnosticsServiceTest extends TestCase
{
    private string $dbFile;
    private StateStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbFile = sys_get_temp_dir() . '/updater-run-report-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->store = new StateStore($this->dbFile);
        $this->store->ensureSchema();
    }

    protected function tearDown(): void
    {
        unset($this->store);
        @unlink($this->dbFile);
        parent::tearDown();
    }

    public function testHistoricoLegadoMostraFalhaOriginalAntesDoRollback(): void
    {
        $id = $this->store->createRun(['target_tag' => 'v5.0.230']);
        $this->store->addRunLog($id, 'info', 'Iniciando etapa da atualização.', ['etapa' => 'health_check']);
        $this->store->addRunLog($id, 'error', 'Falha em etapa da atualização.', [
            'etapa' => 'health_check',
            'erro' => 'HTTP 404 rota /_updater/health',
        ]);
        $this->store->addRunLog($id, 'warning', 'Rollback aplicado para etapa.', ['etapa' => 'git_update']);
        $this->store->updateRunStatus($id, 'failed', ['message' => 'Healthcheck: HTTP 404']);
        $this->store->addMigrationAttempt($id, '2026_10_15_create_table', 'success');

        $report = (new RunDiagnosticsService($this->store))->report($id);

        self::assertIsArray($report);
        self::assertSame('failed', $report['run']['status']);
        self::assertSame('Healthcheck: HTTP 404', $report['error']['message']);
        self::assertSame('health_check', $report['summary']['first_failed_step']);
        self::assertSame(3, $report['summary']['event_count']);
        self::assertSame('failed', $report['stages'][0]['status']);
        self::assertSame('applied', $report['stages'][1]['rollback']);
        self::assertSame('2026_10_15_create_table', $report['migration_attempts'][0]['migration']);
        self::assertSame('health_check', $report['events'][1]['step']);
        self::assertNull((new RunDiagnosticsService($this->store))->report(999999));
    }

    public function testNovosEventosIncluemDetalhesEOutputSanitizado(): void
    {
        $id = $this->store->createRun([
            'api_token' => 'super-private-token',
            'post_update_commands' => ['composer config password=superprivate'],
            'repo_url' => 'https://operator:superpass@example.org/erp.git',
        ]);
        $this->store->addRunLog($id, 'info', 'Iniciando etapa da atualização.', [
            'etapa' => 'post_update_commands',
            'event' => 'start',
        ]);
        $this->store->addRunLog($id, 'info', 'Etapa concluída com sucesso.', [
            'etapa' => 'post_update_commands',
            'event' => 'success',
            'duration_ms' => 452,
            'details' => [
                'stdout_tail' => 'Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.secret',
                'url' => 'https://operator:superpass@example.org/erp.git',
            ],
        ]);

        $service = new RunDiagnosticsService($this->store);
        $report = $service->report($id);
        self::assertSame(452, $report['stages'][0]['duration_ms']);
        $json = $service->toJson($report);
        self::assertStringNotContainsString('super-private-token', $json);
        self::assertStringNotContainsString('superpass', $json);
        self::assertStringNotContainsString('superprivate', $json);
        self::assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9.secret', $json);
        self::assertStringContainsString('[REDACTED]', $json);
        self::assertStringContainsString('post_update_commands', $json);
    }

    public function testExportZipIncluiRelatoriosMasNaoAnexosDaAplicacao(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('Extensão zip não instalada neste ambiente.');
        }

        $id = $this->store->createRun([]);
        $this->store->addRunLog($id, 'error', 'Falha na atualização.', ['etapa' => 'health_check']);
        $service = new RunDiagnosticsService($this->store);
        $zipPath = $service->toZip($service->report($id));
        try {
            $archive = new ZipArchive();
            self::assertTrue($archive->open($zipPath));
            $names = [];
            for ($i = 0; $i < $archive->numFiles; $i++) {
                $names[] = $archive->getNameIndex($i);
            }
            self::assertContains('diagnostico.json', $names);
            self::assertContains('eventos.jsonl', $names);
            self::assertContains('etapas.json', $names);
            self::assertContains('migrations.json', $names);
            self::assertNotContains('.env', $names);
            self::assertNotContains('laravel.log', $names);
            $document = json_decode((string) $archive->getFromName('diagnostico.json'), true);
            self::assertSame($id, $document['run']['id']);
            $archive->close();
        } finally {
            @unlink($zipPath);
        }
    }

    public function testOpcaoJsonSerializadaESegredosPosicionaisNaoSaoExportados(): void
    {
        $result = RunDiagnosticsService::sanitize([
            'options_json' => '{"auth_password":"ULTRA_SECRET_456","token":"BAD_TOKEN_123"}',
            'post_update_commands' => ['composer config --auth http-basic.example.com user ULTRA_SECRET_456'],
            'command' => 'composer config --auth http-basic.example.com user ULTRA_SECRET_456',
        ]);

        $json = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('ULTRA_SECRET_456', $json);
        self::assertStringNotContainsString('BAD_TOKEN_123', $json);
        self::assertStringContainsString('[argumentos omitidos]', $json);
    }

    public function testLogsDeComandosLegadosNaoExibemSegredosPosicionais(): void
    {
        $result = RunDiagnosticsService::sanitize([
            'post_update_commands_log' => [
                'curl https://example.org -H Authorization:privateToken123: executado',
                'echo ULTRA_SECRET_456: executado',
            ],
            'command' => 'echo ULTRA_SECRET_456',
        ]);

        $json = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('ULTRA_SECRET_456', $json);
        self::assertStringNotContainsString('privateToken123', $json);
    }

    public function testDetalhesPorEtapaNaoCopiamContextoCompleto(): void
    {
        $context = [
            'revision_after' => '123abc',
            'options' => ['api_token' => 'secreto'],
            'post_update_command_results' => [['command' => 'composer update', 'stderr_tail' => 'token=abcdef']],
        ];

        self::assertSame(['revision_after' => '123abc'], RunDiagnosticsService::stageDetails('git_update', $context));
        $result = RunDiagnosticsService::stageDetails('post_update_commands', $context);
        self::assertArrayNotHasKey('options', $result);
        self::assertStringNotContainsString('abcdef', json_encode($result));
    }
}
