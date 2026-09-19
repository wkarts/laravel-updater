<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Contracts\CodeDriverInterface;
use Argws\LaravelUpdater\Kernel\UpdaterKernel;
use Argws\LaravelUpdater\Pipeline\UpdatePipeline;
use Argws\LaravelUpdater\Support\EnvironmentDetector;
use Argws\LaravelUpdater\Support\PreflightChecker;
use Argws\LaravelUpdater\Support\RunReportMailer;
use Argws\LaravelUpdater\Support\StateStore;
use Argws\LaravelUpdater\Support\UpdateRecoveryManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class UpdaterKernelCliGuardTest extends TestCase
{
    public function testUpdateRealNuncaLiberaExecucaoHttpNoKernel(): void
    {
        $environment = $this->createMock(EnvironmentDetector::class);
        $environment->expects($this->once())
            ->method('ensureCli')
            ->with(false)
            ->willThrowException(new RuntimeException('guard-stop'));

        $kernel = new UpdaterKernel(
            $environment,
            $this->createMock(UpdatePipeline::class),
            $this->createMock(CodeDriverInterface::class),
            $this->createMock(PreflightChecker::class),
            $this->createMock(StateStore::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(RunReportMailer::class),
            $this->createMock(UpdateRecoveryManager::class)
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('guard-stop');

        $kernel->run([
            'dry_run' => false,
            'allow_http' => true,
        ]);
    }

    public function testDryRunMantemCompatibilidadeComAllowHttp(): void
    {
        $environment = $this->createMock(EnvironmentDetector::class);
        $environment->expects($this->once())
            ->method('ensureCli')
            ->with(true)
            ->willThrowException(new RuntimeException('guard-stop'));

        $kernel = new UpdaterKernel(
            $environment,
            $this->createMock(UpdatePipeline::class),
            $this->createMock(CodeDriverInterface::class),
            $this->createMock(PreflightChecker::class),
            $this->createMock(StateStore::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(RunReportMailer::class),
            $this->createMock(UpdateRecoveryManager::class)
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('guard-stop');

        $kernel->run([
            'dry_run' => true,
            'allow_http' => true,
        ]);
    }
}
