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
