<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Support\GitMaintenance;
use Argws\LaravelUpdater\Support\ShellRunner;
use PHPUnit\Framework\TestCase;

class GitMaintenanceTest extends TestCase
{
    public function testMaintenanceUsaTimeoutENaoExecutaGcAggressivePorPadrao(): void
    {
        $root = sys_get_temp_dir() . '/updater-git-maint-' . uniqid('', true);
        @mkdir($root . '/.git', 0775, true);
        file_put_contents($root . '/.git/large.bin', str_repeat('x', 2 * 1024 * 1024));

        $shell = new class extends ShellRunner {
            /** @var array<int,array{command:array<int,string>,timeout:int}> */
            public array $calls = [];

            public function runWithTimeout(array $command, ?string $cwd = null, array $env = [], int $timeoutSeconds = 600): array
            {
                $this->calls[] = [
                    'command' => array_values(array_map('strval', $command)),
                    'timeout' => $timeoutSeconds,
                ];

                if (($command[0] ?? '') === 'bash') {
                    return [
                        'code' => 1,
                        'stdout' => '',
                        'stderr' => '',
                        'cmd' => implode(' ', $command),
                    ];
                }

                if (($command[0] ?? '') === 'git'
                    && ($command[1] ?? '') === 'remote'
                    && ($command[2] ?? '') === 'get-url') {
                    return [
                        'code' => 0,
                        'stdout' => 'https://example.test/repo.git',
                        'stderr' => '',
                        'cmd' => implode(' ', $command),
                    ];
                }

                return [
                    'code' => 0,
                    'stdout' => '',
                    'stderr' => '',
                    'cmd' => implode(' ', $command),
                ];
            }
        };

        $maintenance = new GitMaintenance($shell, [
            'git' => [
                'path' => $root,
                'branch' => 'main',
            ],
            'git_maintenance' => [
                'enabled' => true,
                'command_timeout_seconds' => 12,
                'size_timeout_seconds' => 5,
                'aggressive_threshold_mb' => 1,
                'allow_aggressive' => false,
                'max_size_mb' => 0,
            ],
        ]);

        $report = $maintenance->maintain('test');

        $commands = array_map(
            static fn (array $call): string => implode(' ', $call['command']),
            $shell->calls
        );

        $this->assertNotEmpty($commands);
        $this->assertFalse(
            (bool) array_filter($commands, static fn (string $command): bool => str_contains($command, '--aggressive'))
        );

        foreach ($shell->calls as $call) {
            if (($call['command'][0] ?? '') === 'git') {
                $this->assertSame(12, $call['timeout']);
            }
        }

        $aggressiveAction = null;
        foreach ((array) ($report['actions'] ?? []) as $action) {
            if (($action['action'] ?? '') === 'gc_aggressive') {
                $aggressiveAction = $action;
                break;
            }
        }

        $this->assertIsArray($aggressiveAction);
        $this->assertTrue((bool) ($aggressiveAction['skipped'] ?? false));

        @unlink($root . '/.git/large.bin');
        @rmdir($root . '/.git');
        @rmdir($root);
    }
}
