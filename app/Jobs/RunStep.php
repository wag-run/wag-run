<?php

namespace App\Jobs;

use App\Engine\StepRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs one pending step. The engine handles retries with new step rows, so
 * the job itself is never retried; if a worker dies, the sweep notices.
 */
class RunStep implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $stepId) {}

    public function handle(StepRunner $runner): void
    {
        $runner->run($this->stepId);
    }
}
