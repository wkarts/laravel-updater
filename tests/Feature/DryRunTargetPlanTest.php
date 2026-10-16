<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Feature;

use Argws\LaravelUpdater\Contracts\CodeDriverInterface;
use Argws\LaravelUpdater\Kernel\UpdaterKernel;
use Argws\LaravelUpdater\Pipeline\UpdatePipeline;
use Argws\LaravelUpdater\Support\EnvironmentDetector;
use Argws\LaravelUpdater\Support\PreflightChecker;
use Argws\LaravelUpdater\Support\RunReportMailer;
use Argws\LaravelUpdater\Support\StateStore;
use Argws\LaravelUpdater\Support\UpdateRecoveryManager;
use Mockery;
use Orchestra\Testbench\TestCase;
use Psr\Log\NullLogger;

final class DryRunTargetPlanTest extends TestCase
{
    public function testSelectedExactTagNeverUsesTheInstalledRemotesLatestCommitAsItsTarget(): void
    {
        [$context, $state] = $this->simulate([
            'source_id' => 7, 'profile_id' => 4, 'update_type' => 'git_tag',
            'target_tag' => 'v2.0.0-rc.3+build.9',
        ]);
        $plan = $context['dry_run_plan'];
        self::assertSame('v2.0.0-rc.3+build.9', $plan['versao_alvo']);
        self::assertSame(7, $plan['alvo_solicitado']['source_id']);
        self::assertSame(4, $plan['alvo_solicitado']['profile_id']);
        self::assertSame('git_tag', $plan['alvo_solicitado']['update_type']);
        self::assertSame('v2.0.0-rc.3+build.9', $plan['alvo_solicitado']['target_tag']);
        self::assertNull($plan['alvo_solicitado']['commit']);
        self::assertNull($plan['diff_commits']);
        self::assertFalse($plan['comparacao']['disponivel']);
        self::assertSame('DRY_RUN', $state->findRun((int) $context['run_id'])['status']);
    }

    public function testSelectedBranchAndSourceStayExplicitWithoutClaimingAnUnverifiedComparison(): void
    {
        [$context] = $this->simulate([
            'source_id' => 8, 'profile_id' => 5, 'update_type' => 'git_merge',
            'target_branch' => 'release/customer-beta',
        ]);
        $plan = $context['dry_run_plan'];
        self::assertSame('release/customer-beta', $plan['versao_alvo']);
        self::assertSame('release/customer-beta', $plan['alvo_solicitado']['target_branch']);
        self::assertSame('not_verified', $plan['alvo_solicitado']['verification']);
        self::assertSame('alvo_solicitado_nao_verificado', $plan['comparacao']['escopo']);
        self::assertNull($plan['diff_commits']);
        self::assertFalse($plan['comparacao']['disponivel']);
    }

    public function testSelectedSourceWithoutAnExactReferenceDoesNotInventItsConfiguredBranch(): void
    {
        config(['updater.git.branch' => 'installed-main']);
        [$context] = $this->simulate(['source_id' => 9, 'update_type' => 'git_ff_only']);
        $plan = $context['dry_run_plan'];
        self::assertSame(9, $plan['alvo_solicitado']['source_id']);
        self::assertNull($plan['versao_alvo']);
        self::assertNull($plan['alvo_solicitado']['target_branch']);
        self::assertNull($plan['alvo_solicitado']['reference']);
        self::assertNull($plan['diff_commits']);
    }

    public function testLegacyDryRunWithoutAnAlternativeTargetKeepsItsRepositoryComparisonLabelled(): void
    {
        [$context] = $this->simulate([], true);
        $plan = $context['dry_run_plan'];
        self::assertSame('installed-remote-head', $plan['versao_alvo']);
        self::assertSame(3, $plan['diff_commits']);
        self::assertTrue($plan['comparacao']['disponivel']);
        self::assertSame('repositorio_atualmente_configurado', $plan['comparacao']['escopo']);
        self::assertNull($plan['alvo_solicitado']['reference']);
        self::assertSame('DRY_RUN', $context['status']);
    }

    private function simulate(array $options, bool $queryConfiguredRepository = false): array
    {
        $state = new StateStore(':memory:');
        $state->ensureSchema();
        $driver = Mockery::mock(CodeDriverInterface::class);
        $driver->shouldReceive('currentRevision')->once()->andReturn('installed-local-head');
        $driver->shouldNotReceive('update');
        if ($queryConfiguredRepository) {
            $driver->shouldReceive('statusUpdates')->once()->andReturn([
                'remote' => 'installed-remote-head', 'latest_tag' => 'unrelated-latest-tag', 'behind_by_commits' => 3,
            ]);
        } else {
            $driver->shouldNotReceive('statusUpdates');
        }
        $pipeline = Mockery::mock(UpdatePipeline::class);
        $pipeline->shouldNotReceive('run');
        $preflight = Mockery::mock(PreflightChecker::class);
        $preflight->shouldReceive('report')->once()->andReturn([]);
        $preflight->shouldNotReceive('validate');
        $recovery = Mockery::mock(UpdateRecoveryManager::class);
        $recovery->shouldReceive('registerFatalGuard')->once();
        $recovery->shouldReceive('disarmFatalGuard')->once();
        $kernel = new UpdaterKernel(new EnvironmentDetector(), $pipeline, $driver, $preflight, $state, new NullLogger(), new RunReportMailer(), $recovery);

        return [$kernel->run(['dry_run' => true, ...$options]), $state];
    }
}
