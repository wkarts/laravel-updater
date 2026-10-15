<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class UpdateTargetSelectionTest extends TestCase
{
    public function testUpdateFormAcceptsTypedPreReleaseTags(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2) . '/resources/views/sections/updates.blade.php');

        $this->assertIsString($view);
        $this->assertStringContainsString('id="target_tag" name="target_tag" type="text" list="available-tags"', $view);
        $this->assertStringContainsString('<datalist id="available-tags">', $view);
        $this->assertStringContainsString("old('target_tag', '')", $view);
        $this->assertStringContainsString('-rc.1', $view);
    }

    public function testBranchOverrideSurvivesApprovalAndReachesCliExecutor(): void
    {
        $controller = file_get_contents(dirname(__DIR__, 2) . '/src/Http/Controllers/OperationsController.php');
        $dispatcher = file_get_contents(dirname(__DIR__, 2) . '/src/Support/TriggerDispatcher.php');
        $command = file_get_contents(dirname(__DIR__, 2) . '/src/Commands/UpdateRunCommand.php');

        $this->assertIsString($controller);
        $this->assertIsString($dispatcher);
        $this->assertIsString($command);
        $this->assertStringContainsString("'target_branch' => \$targetBranch", $controller);
        $this->assertStringContainsString("'target_branch' => (string) (\$pending['target_branch'] ?? '')", $controller);
        $this->assertStringContainsString("'--branch=' . (string) \$options['target_branch']", $dispatcher);
        $this->assertStringContainsString("'target_branch' => \$branch", $command);
    }
}
