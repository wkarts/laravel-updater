<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Support\ShellRunner;
use Argws\LaravelUpdater\Support\StateStore;
use Argws\LaravelUpdater\Support\TriggerDispatcher;
use Argws\LaravelUpdater\Support\UpdateRecoveryManager;
use Argws\LaravelUpdater\Support\UpdaterLockTools;
use PHPUnit\Framework\TestCase;

class TriggerDispatcherTest extends TestCase
{
    public function testBuildUpdateCommandIncluiAllowHttpQuandoAtivo(): void
    {
        $store = new StateStore(sys_get_temp_dir() . '/updater-test-' . uniqid('', true) . '.sqlite');
        $dispatcher = new TriggerDispatcher(
            'sync',
            $store,
            new UpdateRecoveryManager($store, new UpdaterLockTools(), new ShellRunner())
        );

        $reflection = new \ReflectionClass($dispatcher);
        $method = $reflection->getMethod('buildUpdateCommandArgs');
        $method->setAccessible(true);

        $args = $method->invoke($dispatcher, [
            'allow_http' => true,
            'update_type' => 'git_ff_only',
            'source_id' => 2,
            'profile_id' => 7,
            'run_id' => 99,
            'replay_migrations_from_start' => true,
        ]);

        $this->assertContains('--allow-http', $args);
        $this->assertContains('--update-type=git_ff_only', $args);
        $this->assertContains('--source-id=2', $args);
        $this->assertContains('--profile-id=7', $args);
        $this->assertContains('--run-id=99', $args);
        $this->assertContains('--replay-migrations-from-start', $args);
    }
}
