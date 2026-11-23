<?php

namespace App\Engine;

use App\Engine\Events\ExecutionFinished;
use App\Enums\ConcurrencyPolicy;
use App\Enums\ExecutionStatus;
use App\Enums\NodeKind;
use App\Enums\ResumeReason;
use App\Enums\StepStatus;
use App\Enums\WaitType;
use App\Models\Execution;
use App\Models\ExecutionStep;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Services\WorkflowFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use RuntimeException;

/**
 * The engine's public API: start, cancel, and resume runs.
 */
class Engine
{
    public const DEFAULT_MAX_CHAIN_DEPTH = 10;

    public const DEFAULT_MAX_RUNS_PER_MINUTE = 60;

    /**
     * The error code on a run cancelled because its workflow started too
     * many runs in the last minute.
     */
    public const RUN_LIMIT_REACHED = 'run_limit_reached';

    public function __construct(
        protected Coordinator $coordinator,
        protected NodeRegistry $registry,
        protected WorkflowFiles $files,
    ) {}

    /**
     * Start a run of the workflow's published version. Every workflow can be
     * started manually; without a trigger node id, the run starts at its
     * first trigger with $input as that trigger's payload.
     *
     * With $version, the run is pinned to that version instead; a draft
     * snapshot makes it a test run, which ignores the workflow's concurrency
     * setting so testing never skips or cancels live runs.
     *
     * A run another run started, waiting on it ($parentStep) or not
     * ($startedBy), is one level deeper in the chain. Past the organization's
     * maximum chain depth the run is created already cancelled, which stops
     * loops such as A starting B starting A.
     *
     * A workflow may also start only so many runs a minute (its own limit, or
     * the organization's). Runs past it are created already cancelled too,
     * which slows down loops the chain cannot see, such as a workflow whose
     * action fires its own trigger in another app. Test runs do not count.
     *
     * @param  array<string, mixed>  $input
     */
    public function start(
        Workflow $workflow,
        array $input = [],
        ?User $user = null,
        string $triggerType = 'manual',
        ?string $triggerNodeId = null,
        ?ExecutionStep $parentStep = null,
        ?Execution $startedBy = null,
        ?WorkflowVersion $version = null,
    ): Execution {
        $version ??= $workflow->publishedVersion
            ?? throw new RuntimeException(__('engine.workflow_name_has_no_published', ['name' => $workflow->name]));
        $isTest = $version->is_draft;

        $graph = $version->toGraph();
        $triggerNodeId ??= $this->firstTrigger($graph->nodes);

        if ($triggerNodeId === null || ! $graph->has($triggerNodeId)) {
            throw new InvalidArgumentException(__('engine.workflow_name_has_no_trigger', ['name' => $workflow->name]));
        }

        $parent = $parentStep === null ? $startedBy : $parentStep->execution;
        $chainDepth = ($parent === null ? 0 : $parent->chain_depth) + 1;

        $execution = DB::transaction(function () use ($workflow, $version, $isTest, $input, $user, $triggerType, $triggerNodeId, $parentStep, $chainDepth) {
            Workflow::query()->lockForUpdate()->find($workflow->id);

            $inProgress = $workflow->executions()->where('is_test', false)->whereIn('status', ExecutionStatus::inProgress())->get();

            $attributes = [
                'workspace_id' => $workflow->workspace_id,
                'workflow_id' => $workflow->id,
                'workflow_version_id' => $version->id,
                'trigger_type' => $triggerType,
                'trigger_node_id' => $triggerNodeId,
                'input' => Redactor::forWorkspace($workflow->workspace)->mask($input),
                'triggered_by' => $user?->id,
                'parent_step_id' => $parentStep?->id,
                'chain_depth' => $chainDepth,
                'is_test' => $isTest,
                'started_at' => now(),
            ];

            $organization = Organization::current();
            $maxDepth = $organization->max_chain_depth ?? self::DEFAULT_MAX_CHAIN_DEPTH;

            if ($chainDepth > $maxDepth) {
                return $this->stop($attributes, ['message' => __('engine.chain_depth_limit_reached', ['max' => $maxDepth])]);
            }

            $maxRuns = $workflow->max_runs_per_minute ?? $organization->max_runs_per_minute ?? self::DEFAULT_MAX_RUNS_PER_MINUTE;
            $limiterKey = 'workflow-runs:'.$workflow->id;

            if (! $isTest && RateLimiter::tooManyAttempts($limiterKey, $maxRuns)) {
                return $this->stop($attributes, [
                    'message' => __('engine.run_limit_reached', ['max' => $maxRuns]),
                    'code' => self::RUN_LIMIT_REACHED,
                ]);
            }

            if (! $isTest && $inProgress->isNotEmpty() && $workflow->concurrency === ConcurrencyPolicy::SkipIfRunning) {
                $skipped = Execution::create([...$attributes, 'status' => ExecutionStatus::Skipped, 'finished_at' => now()]);

                DB::afterCommit(fn () => event(new ExecutionFinished($skipped)));
                $this->coordinator->notifyParent($skipped);

                return $skipped;
            }

            if (! $isTest && $workflow->concurrency === ConcurrencyPolicy::CancelPrevious) {
                foreach ($inProgress as $previous) {
                    $this->coordinator->locked($previous->id, fn (Execution $locked) => $this->coordinator->finish($locked, ExecutionStatus::Cancelled));
                }
            }

            if (! $isTest) {
                RateLimiter::hit($limiterKey, 60);
            }

            $execution = Execution::create([...$attributes, 'status' => ExecutionStatus::Running]);
            $this->files->claim($execution, $input);
            $this->coordinator->startAt($execution, $triggerNodeId);

            return $execution;
        });

        $this->files->claim($execution, $input);

        return $execution;
    }

    /**
     * Record a run that is stopped before it starts, and tell the run
     * waiting on it, if any.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array{message: string, code?: string}  $error
     */
    protected function stop(array $attributes, array $error): Execution
    {
        $stopped = Execution::create([...$attributes, 'status' => ExecutionStatus::Cancelled, 'finished_at' => now(), 'error' => $error]);

        DB::afterCommit(fn () => event(new ExecutionFinished($stopped)));
        $this->coordinator->notifyParent($stopped);

        return $stopped;
    }

    /**
     * Cancel a run in progress: its open steps, and any child runs they wait
     * on, are marked cancelled.
     */
    public function cancel(Execution $execution, ?User $user = null): void
    {
        $this->coordinator->locked($execution->id, fn (Execution $locked) => $this->coordinator->finish($locked, ExecutionStatus::Cancelled, cancelledBy: $user));
    }

    /**
     * Resume a step that is waiting on an outside response, such as an
     * approval decision or a submitted task form.
     *
     * @param  array<string, mixed>  $payload
     */
    public function respond(ExecutionStep $step, array $payload = []): void
    {
        if ($step->status !== StepStatus::Waiting || $step->wait_type !== WaitType::External) {
            throw new InvalidArgumentException(__('engine.this_step_is_not_waiting'));
        }

        $this->coordinator->dispatchResume($step, ResumeReason::External, $payload);
    }

    /**
     * @param  array<string, Graph\GraphNode>  $nodes
     */
    protected function firstTrigger(array $nodes): ?string
    {
        foreach ($nodes as $node) {
            if ($this->registry->has($node->type) && $this->registry->make($node->type)->kind() === NodeKind::Trigger) {
                return $node->id;
            }
        }

        return null;
    }
}
