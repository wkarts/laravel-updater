<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Support\AuthStore;
use Argws\LaravelUpdater\Support\StateStore;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PDO;
use PHPUnit\Framework\TestCase;

class AuthStoreTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    public function testEnsureSchemaCreatesAuthTables(): void
    {
        $path = sys_get_temp_dir() . '/updater_auth_schema_' . uniqid('', true) . '.sqlite';
        $store = new StateStore($path);
        $authStore = new AuthStore($store);

        $authStore->ensureSchema();

        $tables = $store->pdo()->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);

        $this->assertContains('updater_users', $tables);
        $this->assertContains('updater_sessions', $tables);
        $this->assertContains('updater_login_attempts', $tables);

        @unlink($path);
    }

    public function testEnsureDefaultAdminIsIdempotent(): void
    {
        $container = new Container();
        $container->instance('config', new Repository([
            'updater' => [
                'ui' => [
                    'auth' => [
                        'enabled' => true,
                        'auto_provision_admin' => true,
                        'default_email' => 'admin@admin.com',
                        'default_password' => '123456',
                    ],
                ],
            ],
        ]));
        Container::setInstance($container);

        $path = sys_get_temp_dir() . '/updater_auth_admin_' . uniqid('', true) . '.sqlite';
        $stateStore = new StateStore($path);
        $stateStore->ensureSchema();
        $authStore = new AuthStore($stateStore);

        $authStore->ensureDefaultAdmin();
        $authStore->ensureDefaultAdmin();

        $count = (int) $stateStore->pdo()->query("SELECT COUNT(*) FROM updater_users WHERE email = 'admin@admin.com'")->fetchColumn();

        $this->assertSame(1, $count);

        @unlink($path);
    }
}
