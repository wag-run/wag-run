<?php

namespace App\Engine;

use App\Enums\ExecutionStatus;
use App\Enums\ResumeReason;
use App\Enums\StepStatus;
use App\Enums\WaitType;
use App\Models\Execution;
use App\Models\ExecutionStep;

/**
 * The scheduled safety net. Queue workers run steps; the sweep catches rows
 * that are ready but unqueued, stuck in running, or past their timer.
 */
class Sweeper
{
    /**
     * A pending step not picked up within this many seconds is queued again.
     * Duplicate jobs are harmless: only one worker can claim a step.
     */
    public const REQUEUE_AFTER = 60;

    /**
     * Grace added to a node's timeout before its worker is treated as dead.
     */
    public const WORKER_GRACE = 60;

    public function __construct(
        protected Coordinator $coordinator,
        protected NodeRegistry $registry,
    ) {}

    /**
     * @return array{requeued: int, worker_lost: int, resumed: int, resettled: int}
     */
    public function sweep(): array
    {
        return [
            'requeued' => $this->requeueStalePending(),
            'worker_lost' => $this->failLostWorkers(),
            'resumed' => $this->resumeElapsedWaits(),
            'resettled' => $this->resettleWaitingExecutions(),
        ];
    }

    protected function requeueStalePending(): int
    {
        $threshold = now()->subSeconds(self::REQUEUE_AFTER);

        $steps = ExecutionStep::query()
            ->where('status', StepStatus::Pending)
            ->where('available_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('queued_at')
                ->orWhere(fn ($query) => $query->where('queued_at', '<=', $threshold)->where('available_at', '<=', $threshold)))
            ->limit(500)
            ->get();

        foreach ($steps as $step) {
            $this->coordinator->dispatchRun($step);
        }

        return $steps->count();
    }

    protected function failLostWorkers(): int
    {
        $lost = 0;

        $candidates = ExecutionStep::query()
            ->where('status', StepStatus::Running)
            ->where('heartbeat_at', '<=', now()->subSeconds(self::WORKER_GRACE))
            ->limit(500)
            ->get();

        foreach ($candidates as $step) {
            $timeout = $this->registry->has($step->node_type) ? $this->registry->make($step->node_type)->timeout() : 0;

            if ($step->heartbeat_at->addSeconds($timeout + self::WORKER_GRACE)->isFuture()) {
                continue;
            }

            $this->coordinator->workerLost($step);
            $lost++;
        }

        return $lost;
    }

    protected function resumeElapsedWaits(): int
    {
        $steps = ExecutionStep::query()
            ->where('status', StepStatus::Waiting)
            ->whereIn('wait_type', [WaitType::Timer, WaitType::External])
            ->where('resume_at', '<=', now())
            ->limit(500)
            ->get();

        foreach ($steps as $step) {
            $this->coordinator->dispatchResume($step, ResumeReason::TimerElapsed);
        }

        return $steps->count();
    }

    protected function resettleWaitingExecutions(): int
    {
        $executions = Execution::query()
            ->whereIn('status', ExecutionStatus::inProgress())
            ->whereDoesntHave('steps', fn ($query) => $query->whereIn('status', [StepStatus::Pending, StepStatus::Running]))
            ->whereHas('steps', fn ($query) => $query->where('status', StepStatus::Waiting)->whereIn('wait_type', [WaitType::Loop, WaitType::Join]))
            ->limit(200)
            ->get();

        foreach ($executions as $execution) {
            $this->coordinator->resettle($execution);
        }

        return $executions->count();
    }
}
