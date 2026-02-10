<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Tests\Unit;

use Argws\LaravelUpdater\Pipeline\Steps\SeedStep;
use Argws\LaravelUpdater\Support\ShellRunner;
use PHPUnit\Framework\TestCase;

class SeedStepTest extends TestCase
{
    public function testShouldRunSomenteQuandoConfigurado(): void
    {
        $step = new SeedStep(new ShellRunner());

        $this->assertFalse($step->shouldRun(['options' => []]));
        $this->assertTrue($step->shouldRun(['options' => ['seed' => true]]));
        $this->assertTrue($step->shouldRun(['options' => ['seeders' => ['UsersSeeder']]]));
    }
}
