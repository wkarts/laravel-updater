<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Support\AuthStore;
use Argws\LaravelUpdater\Support\ManagerStore;
use Argws\LaravelUpdater\Support\SecretVault;
use Argws\LaravelUpdater\Support\StateStore;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Encryption\Encrypter;
use PHPUnit\Framework\TestCase;

class SecretStorageTest extends TestCase
{
    private StateStore $state;
    private ManagerStore $manager;
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $container = new Container();
        $container->instance('config', new Repository(['updater' => []]));
        $container->instance('encrypter', new Encrypter(random_bytes(32), 'AES-256-CBC'));
        Container::setInstance($container);
        $this->path = sys_get_temp_dir() . '/updater-secrets-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->state = new StateStore($this->path);
        $this->state->ensureSchema();
        $this->manager = new ManagerStore($this->state);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        @unlink($this->path);
        parent::tearDown();
    }

    public function testSourceSecretsAreEncryptedAndNeverReturnedInPublicPayload(): void
    {
        $source = $this->source(['auth_password' => 'source-password', 'token_encrypted' => 'source-token']);
        $raw = $this->state->pdo()->query('SELECT * FROM updater_sources')->fetch(\PDO::FETCH_ASSOC);
        $this->assertTrue(SecretVault::encrypted($raw['auth_password']));
        $this->assertTrue(SecretVault::encrypted($raw['token_encrypted']));
        $this->assertSame('source-password', $source['auth_password']);
        $this->assertSame('source-token', $source['token_encrypted']);
        $public = $this->manager->publicSource($source);
        $this->assertArrayNotHasKey('auth_password', $public);
        $this->assertArrayNotHasKey('token_encrypted', $public);
        $this->assertTrue($public['auth_password_configured']);
        $this->assertStringNotContainsString('source-token', json_encode($public));
        $this->assertStringNotContainsString('source-password', json_encode($this->manager->publicSources()));
    }

    public function testLegacySourceIsMigratedWithoutChangingItsUsableCredential(): void
    {
        $source = $this->source();
        $this->state->pdo()->prepare('UPDATE updater_sources SET auth_password=:password WHERE id=:id')->execute([
            ':password' => 'legacy-secret', ':id' => $source['id'],
        ]);
        $this->assertSame('legacy-secret', $this->manager->findSource((int) $source['id'])['auth_password']);
        $raw = $this->state->pdo()->query('SELECT auth_password FROM updater_sources')->fetchColumn();
        $this->assertTrue(SecretVault::encrypted($raw));
        $this->assertNotSame('legacy-secret', $raw);
    }

    public function testBlankSourceCredentialsPreserveExistingValuesAndRemovalIsExplicit(): void
    {
        $source = $this->source(['auth_password' => 'keep-this']);
        $this->manager->createOrUpdateSource([...$source, 'name' => 'Renamed', 'auth_password' => ''], (int) $source['id']);
        $this->assertSame('keep-this', $this->manager->findSource((int) $source['id'])['auth_password']);
        $this->manager->createOrUpdateSource([...$source, 'remove_credentials' => true], (int) $source['id']);
        $this->assertNull($this->manager->findSource((int) $source['id'])['auth_password']);
    }

    public function testCloudSecretsAreProtectedPreservedRotatedAndRemovable(): void
    {
        $this->manager->saveBackupUploadSettings(['provider' => 's3', 's3' => ['secret_key' => 'cloud-one', 'access_key' => 'key-one']]);
        $raw = $this->state->pdo()->query("SELECT value_json FROM updater_runtime_settings WHERE key='backup_upload'")->fetchColumn();
        $this->assertStringNotContainsString('cloud-one', $raw);
        $this->assertStringNotContainsString('key-one', $raw);
        $this->assertSame('cloud-one', $this->manager->backupUploadSettings()['s3']['secret_key']);
        $this->manager->saveBackupUploadSettings(['provider' => 's3', 's3' => ['secret_key' => '']]);
        $this->assertSame('cloud-one', $this->manager->backupUploadSettings()['s3']['secret_key']);
        $this->manager->saveBackupUploadSettings(['provider' => 's3', 's3' => ['secret_key' => 'cloud-two']]);
        $this->assertSame('cloud-two', $this->manager->backupUploadSettings()['s3']['secret_key']);
        $this->assertArrayNotHasKey('secret_key', $this->manager->publicBackupUploadSettings()['s3']);
        $this->manager->saveBackupUploadSettings(['provider' => 's3', 's3' => ['remove_secret_key' => true]]);
        $this->assertSame('', $this->manager->backupUploadSettings()['s3']['secret_key']);
    }

    public function testRuntimeSecretOverridesAreEncryptedAndTransparentToBackend(): void
    {
        $this->manager->setRuntimeOption('updater_config_overrides', ['updater.sync_token' => 'runtime-secret', 'updater.git.branch' => 'main']);
        $raw = $this->state->pdo()->query("SELECT value_json FROM updater_runtime_settings WHERE key='updater_config_overrides'")->fetchColumn();
        $this->assertStringNotContainsString('runtime-secret', $raw);
        $this->assertSame('runtime-secret', $this->manager->getRuntimeOption('updater_config_overrides')['updater.sync_token']);
    }

    public function testTotpSecretsAreProtectedAndRemainUsableForLogin(): void
    {
        $auth = new AuthStore($this->state);
        $id = $auth->createUser('test@example.test', 'password');
        $auth->updateTotp($id, 'JBSWY3DPEHPK3PXP', true);
        $raw = $this->state->pdo()->query('SELECT totp_secret FROM updater_users')->fetchColumn();
        $this->assertTrue(SecretVault::encrypted($raw));
        $this->assertSame('JBSWY3DPEHPK3PXP', $auth->findUserById($id)['totp_secret']);
        $session = $auth->createSession($id, null, null, 30);
        $this->assertSame('JBSWY3DPEHPK3PXP', $auth->findValidSession($session)['totp_secret']);
    }

    public function testSecretsAreRedactedBeforeAuditAndRunPersistence(): void
    {
        $this->manager->addAuditLog(null, 'test', ['token' => 'audit-secret', 'url' => 'https://bare-token@example.test/repo']);
        $audit = $this->state->pdo()->query('SELECT meta_json FROM updater_audit_logs')->fetchColumn();
        $this->assertStringNotContainsString('audit-secret', $audit);
        $this->assertStringNotContainsString('bare-token', $audit);
        $id = $this->state->createRun(['dry_run' => true, 'auth_password' => 'run-secret']);
        $this->state->addRunLog($id, 'error', 'https://bare-token@example.test/repo', ['secret_key' => 'log-secret']);
        $row = $this->state->pdo()->query('SELECT * FROM updater_logs')->fetch(\PDO::FETCH_ASSOC);
        $this->assertStringNotContainsString('bare-token', $row['message']);
        $this->assertStringNotContainsString('log-secret', $row['context_json']);
        $this->assertStringNotContainsString('run-secret', $this->state->findRun($id)['options_json']);
    }

    public function testBlankProfileCommandsKeepTrustedConfigurationUntilExplicitClear(): void
    {
        $this->manager->createOrUpdateProfile(['name' => 'Ops', 'pre_update_commands' => 'php artisan trusted:before', 'post_update_commands' => 'php artisan trusted:after']);
        $profile = $this->manager->profiles()[0];
        $this->manager->createOrUpdateProfile(['name' => 'Renamed', 'pre_update_commands' => '', 'post_update_commands' => ''], (int) $profile['id']);
        $this->assertSame('php artisan trusted:before', $this->manager->findProfile((int) $profile['id'])['pre_update_commands']);
        $this->manager->createOrUpdateProfile(['name' => 'Renamed', 'clear_pre_update_commands' => true], (int) $profile['id']);
        $this->assertNull($this->manager->findProfile((int) $profile['id'])['pre_update_commands']);
    }

    private function source(array $overrides = []): array
    {
        $this->manager->createOrUpdateSource(array_merge([
            'name' => 'Primary', 'type' => 'git_merge', 'repo_url' => 'https://example.test/repo.git', 'branch' => 'main', 'auth_mode' => 'token', 'active' => 1,
        ], $overrides));

        return $this->manager->activeSource();
    }
}
