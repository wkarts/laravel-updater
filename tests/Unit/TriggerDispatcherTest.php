<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Support\StateStore;
use Argws\LaravelUpdater\Support\TriggerDispatcher;
use PHPUnit\Framework\TestCase;

class TriggerDispatcherTest extends TestCase
{
    public function testBuildUpdateCommandIncluiAllowHttpQuandoAtivo(): void
    {
        $dispatcher = new TriggerDispatcher('sync', new StateStore(sys_get_temp_dir() . '/updater-test-' . uniqid('', true) . '.sqlite'));

        $reflection = new \ReflectionClass($dispatcher);
        $method = $reflection->getMethod('buildUpdateCommandArgs');
        $method->setAccessible(true);

        $args = $method->invoke($dispatcher, [
            'allow_http' => true,
            'update_type' => 'git_ff_only',
            'source_id' => 2,
            'profile_id' => 7,
        ]);

        $this->assertContains('--allow-http', $args);
        $this->assertContains('--update-type=git_ff_only', $args);
        $this->assertContains('--source-id=2', $args);
        $this->assertContains('--profile-id=7', $args);
    }
}
