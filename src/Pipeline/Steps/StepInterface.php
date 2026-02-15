<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Pipeline\Steps;

use Argws\LaravelUpdater\Contracts\PipelineStepInterface;

/**
 * Backward-compat alias for older namespace usages.
 * New steps should implement Contracts\PipelineStepInterface directly.
 */
interface StepInterface extends PipelineStepInterface
{
}
