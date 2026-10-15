<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use PHPUnit\Framework\TestCase;

class UpdaterKernelPipelineSafetyTest extends TestCase
{
    public function testGitMaintenanceNaoParticipaDaPipelineCritica(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Kernel/UpdaterKernel.php');

        $this->assertIsString($source);
        $this->assertStringNotContainsString('new GitMaintenanceStep(', $source);
    }

    public function testHealthcheckMantemMaintenanceELockAteValidacao(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Kernel/UpdaterKernel.php');
        $this->assertIsString($source);

        $maintenance = strpos($source, 'new MaintenanceOnStep(');
        $health = strpos($source, 'new HealthCheckStep(');
        $release = strpos($source, 'new MaintenanceOffStep(');

        $this->assertNotFalse($maintenance);
        $this->assertNotFalse($health);
        $this->assertNotFalse($release);
        $this->assertLessThan($health, $maintenance);
        $this->assertLessThan($release, $health);
    }

    public function testRouteCacheEAtualizadoDepoisDosComandosPosteriores(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Kernel/UpdaterKernel.php');
        self::assertIsString($source);

        $commands = strpos($source, 'new PostUpdateCommandsStep(');
        $cache = strpos($source, 'new CacheClearStep(');
        $health = strpos($source, 'new HealthCheckStep(');
        self::assertNotFalse($commands);
        self::assertNotFalse($cache);
        self::assertNotFalse($health);
        self::assertLessThan($cache, $commands);
        self::assertLessThan($health, $cache);
    }

    public function testBackupAconteceAntesDaManutencaoEDoGitUpdate(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Kernel/UpdaterKernel.php');

        $this->assertIsString($source);

        $backup = strpos($source, 'new FullBackupStep(');
        $maintenance = strpos($source, 'new MaintenanceOnStep(');
        $gitUpdate = strpos($source, 'new GitUpdateStep(');

        $this->assertNotFalse($backup);
        $this->assertNotFalse($maintenance);
        $this->assertNotFalse($gitUpdate);
        $this->assertLessThan($maintenance, $backup);
        $this->assertLessThan($gitUpdate, $maintenance);
    }
}
