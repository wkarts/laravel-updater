<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Support\StateStore;
use Argws\LaravelUpdater\Webhooks\WebhookDestination;
use Argws\LaravelUpdater\Webhooks\WebhookDispatcher;
use Argws\LaravelUpdater\Webhooks\WebhookStore;
use Argws\LaravelUpdater\Webhooks\WebhookTransport;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Encryption\Encrypter;
use PHPUnit\Framework\TestCase;

final class WebhookDeliveryTest extends TestCase
{
    private WebhookStore $store;
    private StateStore $state;
    private string $path;
    private int $endpoint;

    protected function setUp(): void
    {
        parent::setUp();
        $container = new Container();
        $container->instance('config', new Repository(['queue' => ['default' => 'sync']]));
        Container::setInstance($container);
        $this->path = sys_get_temp_dir() . '/updater_webhook_delivery_' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->state = new StateStore($this->path);
        $this->state->ensureSchema();
        $this->store = new WebhookStore($this->state, new Encrypter(random_bytes(32), 'AES-256-CBC'));
        $endpoint = $this->store->saveEndpoint(['name' => 'Public', 'direction' => 'outgoing', 'url' => 'https://hooks.company.com/events',
            'events' => WebhookStore::EVENTS, 'actions' => [], 'enabled' => true]);
        $this->endpoint = $endpoint['endpoint']['id'];
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        Container::setInstance(null);
        parent::tearDown();
    }

    public function testDeliveryIsIdempotentAndPayloadExcludesSecretsAndCommands(): void
    {
        $transport = $this->createMock(WebhookTransport::class);
        $transport->expects(self::once())->method('send')->willReturnCallback(static function ($url, $secret, $eventId, $body): int {
            $payload = json_decode($body, true);
            self::assertSame(['run_id' => 7, 'status' => 'success'], $payload['data']);
            self::assertStringNotContainsString('password', $body);
            self::assertStringNotContainsString('shell', $body);
            return 204;
        });
        $dispatcher = new WebhookDispatcher($this->store, $transport);
        $id = bin2hex(random_bytes(16));
        $dispatcher->publish('update.completed', ['run_id' => 7, 'status' => 'success', 'password' => 'private', 'shell' => 'rm'], $id);
        $dispatcher->publish('update.completed', ['run_id' => 7], $id);
        $dispatcher->processPending();
        $dispatcher->processPending();
        $history = $this->store->history()['deliveries'];
        self::assertCount(1, $history);
        self::assertSame('delivered', $history[0]['status']);
        self::assertSame(1, $history[0]['attempts']);
        self::assertSame(204, $history[0]['response_code']);
        self::assertTrue($this->store->retry((int) $history[0]['id']));
        self::assertSame($id, $this->store->delivery((int) $history[0]['id'])['event_id']);
    }

    public function testTimeoutRetriesWithBackoffAndStopsAfterFiveAttempts(): void
    {
        $transport = $this->createMock(WebhookTransport::class);
        $transport->expects(self::exactly(5))->method('send')->willThrowException(new \RuntimeException('timeout'));
        $dispatcher = new WebhookDispatcher($this->store, $transport);
        $dispatcher->publish('webhook.test', [], null, $this->endpoint);
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $before = time();
            $dispatcher->processPending();
            $delivery = $this->store->history()['deliveries'][0];
            self::assertSame($attempt, $delivery['attempts']);
            self::assertSame($attempt < 5 ? 'retry' : 'failed', $delivery['status']);
            self::assertSame('timeout', $delivery['error_code']);
            if ($attempt < 5) {
                self::assertGreaterThanOrEqual($before + [1 => 60, 2 => 300, 3 => 1800, 4 => 7200][$attempt], $delivery['next_attempt_at']);
                $this->store->pdo()->exec('UPDATE updater_webhook_deliveries SET next_attempt_at=' . (time() - 1));
            }
        }
        self::assertNull($delivery['next_attempt_at']);
    }

    public function testRedirectsAndSsrfFailureArePermanentAndNoExceptionsArePersisted(): void
    {
        $transport = $this->createMock(WebhookTransport::class);
        $calls = 0;
        $transport->expects(self::exactly(2))->method('send')->willReturnCallback(static function () use (&$calls): int {
            if (++$calls === 1) { return 302; }
            throw new \InvalidArgumentException('secret internal IP');
        });
        $dispatcher = new WebhookDispatcher($this->store, $transport);
        $dispatcher->publish('webhook.test', [], null, $this->endpoint);
        $dispatcher->processPending();
        $delivery = $this->store->history()['deliveries'][0];
        self::assertSame('failed', $delivery['status']);
        self::assertSame('redirect_rejected', $delivery['error_code']);
        $dispatcher->publish('webhook.test', [], null, $this->endpoint);
        $dispatcher->processPending();
        $delivery = $this->store->history()['deliveries'][0];
        self::assertSame('failed', $delivery['status']);
        self::assertSame('destination_rejected', $delivery['error_code']);
        self::assertStringNotContainsString('secret internal', json_encode($this->store->history()));
    }

    public function testDisabledEndpointCannotDeliverAndQueueClaimCannotRunTwice(): void
    {
        $transport = $this->createMock(WebhookTransport::class);
        $transport->expects(self::never())->method('send');
        $dispatcher = new WebhookDispatcher($this->store, $transport);
        $dispatcher->publish('webhook.test', [], null, $this->endpoint);
        $this->store->saveEndpoint(['name' => 'Disabled', 'direction' => 'outgoing', 'url' => 'https://hooks.company.com', 'events' => [], 'actions' => [], 'enabled' => false], $this->endpoint);
        $dispatcher->processPending();
        self::assertSame('cancelled', $this->store->history()['deliveries'][0]['status']);
        self::assertFalse($this->store->claim(1, 1));
    }

    public function testBackupMigrationSimulationAndRollbackRunsNeverBecomeUpdateEvents(): void
    {
        foreach ([['manual_backup' => 'full'], ['manual_migration_reapply' => true], ['dry_run' => true], ['rollback' => true]] as $options) {
            $stmt = $this->state->pdo()->prepare("INSERT INTO runs(started_at,finished_at,status,options_json) VALUES('2026-01-01','2099-01-01','success',?)");
            $stmt->execute([json_encode($options)]);
        }
        $transport = $this->createMock(WebhookTransport::class);
        $transport->expects(self::never())->method('send');
        $dispatcher = new WebhookDispatcher($this->store, $transport);
        $dispatcher->processPending();
        $dispatcher->processPending();
        self::assertSame([], $this->store->history()['deliveries']);
        self::assertSame(4, (int) $this->store->pdo()->query('SELECT COUNT(*) FROM updater_webhook_observed')->fetchColumn());
    }

    public function testSchedulerObservesCompletedOperationsOnlyOnceWithoutSendingInternalDetails(): void
    {
        $this->state->pdo()->exec("INSERT INTO runs(started_at,finished_at,status,options_json,error_json) VALUES ('2026-01-01','2099-01-01','failed','{\"password\":\"private\"}','{\"message\":\"private\"}')");
        $transport = $this->createMock(WebhookTransport::class);
        $transport->expects(self::once())->method('send')->willReturn(200);
        $dispatcher = new WebhookDispatcher($this->store, $transport);
        $dispatcher->processPending();
        $dispatcher->processPending();
        self::assertCount(1, $this->store->history()['deliveries']);
        $payload = $this->store->delivery(1)['payload_json'];
        self::assertStringNotContainsString('private', $payload);
        self::assertStringContainsString('update.failed', $payload);
    }
}
