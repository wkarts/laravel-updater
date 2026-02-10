<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Feature;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;
use Argws\LaravelUpdater\Pipeline\UpdatePipeline;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class UpdatePipelineTest extends TestCase
{
    public function testExecutaPipelineENaoFalha(): void
    {
        $step = new class implements PipelineStepInterface {
            public function name(): string { return 'fake'; }
            public function shouldRun(array $context): bool { return true; }
            public function handle(array &$context): void { $context['ok'] = true; }
            public function rollback(array &$context): void { $context['rolled'] = true; }
        };

        $pipeline = new UpdatePipeline([$step], new Logger('test'));
        $context = [];
        $pipeline->run($context);

        $this->assertTrue($context['ok']);
    }
}
