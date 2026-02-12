<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Feature;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Pipeline\UpdatePipeline;
use Argws\LaravelUpdater\Support\StateStore;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class RollbackTest extends TestCase
{
    public function testExecutaRollbackQuandoFalha(): void
    {
        $context = [];

        $okStep = new class implements PipelineStepInterface {
            public function name(): string { return 'ok'; }
            public function shouldRun(array $context): bool { return true; }
            public function handle(array &$context): void { $context['step1'] = true; }
            public function rollback(array &$context): void { $context['rollback1'] = true; }
        };

        $failStep = new class implements PipelineStepInterface {
            public function name(): string { return 'fail'; }
            public function shouldRun(array $context): bool { return true; }
            public function handle(array &$context): void { throw new \RuntimeException('erro'); }
            public function rollback(array &$context): void { $context['rollback2'] = true; }
        };

        $pipeline = new UpdatePipeline([$okStep, $failStep], new Logger('test'), new StateStore(sys_get_temp_dir() . '/updater-test-' . uniqid() . '-2.sqlite'));

        $this->expectException(\Throwable::class);
        try {
            $pipeline->run($context);
        } finally {
            $this->assertTrue($context['rollback1']);
        }
    }
}
