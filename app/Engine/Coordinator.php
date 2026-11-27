<?php

namespace App\Engine;

use App\Engine\Events\ExecutionFinished;
use App\Engine\Graph\WorkflowGraph;
use App\Enums\ExecutionStatus;
use App\Enums\ResumeReason;
use App\Enums\StepStatus;
use App\Enums\WaitType;
use App\Jobs\ResumeStep;
use App\Jobs\RunStep;
use App\Models\Execution;
use App\Models\ExecutionStep;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Owns every status change of steps and executions. Each change runs in a
 * transaction holding the execution's row lock, so parallel branches finishing
 * at once cannot race. Jobs are dispatched only after the transaction commits.
 */
class Coordinator
{
    public const QUEUE = 'workflows';

    /**
     * @var array<int, WorkflowGraph>
     */
    protected array $graphs = [];

    public function __construct(protected NodeRegistry $registry) {}

    /**
     * Apply what a node returned for a step this worker claimed. The result is
     * dropped if the step was cancelled or reclaimed in the meantime.
     */
    public function apply(ExecutionStep $step, string $claimToken, NodeResult $result): void
    {
        $this->locked($step->execution_id, function (Execution $execution) use ($step, $claimToken, $result) {
            $fresh = ExecutionStep::query()->whereKey($step->id)->first();

            if (! $fresh || $fresh->status !== StepStatus::Running || $fresh->claim_token !== $claimToken) {
                return;
            }

            $fresh->setRelation('execution', $execution);
            $redactor = Redactor::forWorkspace($execution->workspace);

            match ($result->kind) {
                ResultKind::Complete => $this->complete($fresh, $result->output, $result->ports, $redactor),
                ResultKind::Fail => $this->fail($fresh, (string) $result->error, $result->retryable, $result->output, $redactor),
                ResultKind::Wait => $this->park($fresh, $result->external ? WaitType::External : WaitType::Timer, $result->until, $result->state, $redactor),
                ResultKind::Terminate => $this->terminate($fresh, $result, $redactor),
                ResultKind::Iterate => $this->iterate($fresh, $result, $redactor),
                ResultKind::RunChild => $this->runChild($fresh, $result, $redactor),
            };

            $this->settle($execution);
        });
    }

    /**
     * The sweep found a running step whose worker stopped responding: it is
     * failed and retried according to its retry setting.
     */
    public function workerLost(ExecutionStep $step): void
    {
        $this->locked($step->execution_id, function (Execution $execution) use ($step) {
            $fresh = ExecutionStep::query()->whereKey($step->id)->first();

            if (! $fresh || $fresh->status !== StepStatus::Running || ! $fresh->heartbeat_at?->eq($step->heartbeat_at)) {
                return;
            }

            $fresh->setRelation('execution', $execution);

            $this->fail($fresh, __('engine.worker_running_this_step_stopped'), true, null, Redactor::forWorkspace($execution->workspace));
            $this->settle($execution);
        });
    }

    /**
     * Create the first step of a new execution.
     */
    public function startAt(Execution $execution, string $triggerNodeId): ExecutionStep
    {
        return $this->createStep($execution, $triggerNodeId, '', null, null);
    }

    /**
     * End an execution: cancel every open step and any child runs they wait on.
     *
     * @param  array<string, mixed>|null  $error
     */
    public function finish(Execution $execution, ExecutionStatus $status, mixed $output = null, ?array $error = null, ?User $cancelledBy = null): void
    {
        if ($execution->status->isFinished()) {
            return;
        }

        $open = $execution->openSteps()->get();

        foreach ($open as $openStep) {
            $openStep->forceFill(['status' => StepStatus::Cancelled, 'finished_at' => now(), 'claim_token' => null])->save();

            foreach ($openStep->childExecutions()->whereIn('status', ExecutionStatus::inProgress())->get() as $child) {
                $this->locked($child->id, fn (Execution $lockedChild) => $this->finish($lockedChild, ExecutionStatus::Cancelled, cancelledBy: $cancelledBy));
            }
        }

        $execution->forceFill([
            'status' => $status,
            'output' => $output,
            'error' => $error,
            'cancelled_by' => $cancelledBy?->id,
            'finished_at' => now(),
        ])->save();

        DB::afterCommit(fn () => event(new ExecutionFinished($execution)));

        $this->notifyParent($execution);
    }

    /**
     * Hand a finished child execution's result to the step waiting on it.
     */
    public function notifyParent(Execution $execution): void
    {
        if (! $execution->parent_step_id) {
            return;
        }

        $parent = ExecutionStep::query()->whereKey($execution->parent_step_id)->first();

        if (! $parent || $parent->status !== StepStatus::Waiting || $parent->wait_type !== WaitType::Child) {
            return;
        }

        [$reason, $payload] = $execution->status === ExecutionStatus::Complete
            ? [ResumeReason::ChildCompleted, ['output' => $execution->output, 'execution_id' => $execution->id]]
            : [ResumeReason::ChildFailed, [
                'execution_id' => $execution->id,
                'message' => match ($execution->status) {
                    ExecutionStatus::Skipped => __('engine.child_workflow_was_skipped_because'),
                    ExecutionStatus::Cancelled => $execution->error['message'] ?? __('engine.child_workflow_was_cancelled'),
                    default => $execution->error['message'] ?? __('engine.child_workflow_failed'),
                },
            ]];

        $this->dispatchResume($parent, $reason, $payload);
    }

    /**
     * Re-check loops, merges and status for an execution. The sweep calls this
     * as a safety net; normally every change settles its own execution.
     */
    public function resettle(Execution $execution): void
    {
        $this->locked($execution->id, fn (Execution $locked) => $this->settle($locked));
    }

    public function dispatchRun(ExecutionStep $step): void
    {
        $step->newQuery()->whereKey($step->id)->update(['queued_at' => now()]);

        $job = RunStep::dispatch($step->id)->onQueue(self::QUEUE)->afterCommit();

        if ($step->available_at?->isFuture()) {
            $job->delay($step->available_at);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatchResume(ExecutionStep $step, ResumeReason $reason, array $payload = []): void
    {
        ResumeStep::dispatch($step->id, $reason, $payload)->onQueue(self::QUEUE)->afterCommit();
    }

    public function graphFor(Execution $execution): WorkflowGraph
    {
        return $this->graphs[$execution->workflow_version_id] ??= $execution->version->toGraph();
    }

    /**
     * @template TReturn
     *
     * @param  callable(Execution): TReturn  $callback
     * @return TReturn|null
     */
    public function locked(string $executionId, callable $callback): mixed
    {
        return DB::transaction(function () use ($executionId, $callback) {
            $execution = Execution::query()->lockForUpdate()->find($executionId);

            return $execution ? $callback($execution) : null;
        });
    }

    /**
     * @param  list<string>  $ports
     */
    protected function complete(ExecutionStep $step, mixed $output, array $ports, Redactor $redactor): void
    {
        $step->forceFill([
            'status' => StepStatus::Complete,
            'output' => $redactor->mask($output),
            'output_ports' => $ports,
            'finished_at' => now(),
            'claim_token' => null,
        ])->save();

        foreach ($ports as $port) {
            $this->follow($step, $port);
        }
    }

    protected function fail(ExecutionStep $step, string $message, bool $retryable, mixed $output, Redactor $redactor): void
    {
        $execution = $step->execution;
        $graph = $this->graphFor($execution);
        $graphNode = $graph->node($step->node_id);
        $policy = $this->registry->make($step->node_type)->defaultRetryPolicy()->merge($graphNode->retry);

        $step->forceFill([
            'status' => StepStatus::Failed,
            'output' => $redactor->mask($output),
            'error' => ['message' => $redactor->mask($message), 'attempt' => $step->attempt],
            'finished_at' => now(),
            'claim_token' => null,
        ])->save();

        if ($retryable && $policy->allowsAnotherAttempt($step->attempt)) {
            $this->createStep(
                $execution,
                $step->node_id,
                $step->scope_key,
                $step->previousStep,
                $step->entry_port,
                attempt: $step->attempt + 1,
                retryOf: $step,
                availableAt: now()->addSeconds($policy->delayAfter($step->attempt)),
                state: $step->state,
            );

            return;
        }

        if ($graph->outgoing($step->node_id, 'error') !== []) {
            $this->follow($step, 'error');

            return;
        }

        $this->finish($execution, ExecutionStatus::Failed, error: [
            'message' => $redactor->mask($message),
            'node_id' => $step->node_id,
            'step_id' => $step->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function park(ExecutionStep $step, WaitType $type, ?CarbonInterface $until, array $state, Redactor $redactor): void
    {
        $step->forceFill([
            'status' => StepStatus::Waiting,
            'wait_type' => $type,
            'resume_at' => $until,
            'state' => $redactor->mask($state) ?: null,
            'claim_token' => null,
        ])->save();
    }

    protected function terminate(ExecutionStep $step, NodeResult $result, Redactor $redactor): void
    {
        $step->forceFill([
            'status' => StepStatus::Complete,
            'output' => $redactor->mask($result->output),
            'output_ports' => [],
            'finished_at' => now(),
            'claim_token' => null,
        ])->save();

        $status = $result->terminateAs ?? ExecutionStatus::Complete;

        $this->finish(
            $step->execution,
            $status,
            output: $redactor->mask($result->output),
            error: $status === ExecutionStatus::Failed
                ? ['message' => __('engine.ended_by_a_terminate_node'), 'node_id' => $step->node_id, 'step_id' => $step->id]
                : null,
        );
    }

    protected function iterate(ExecutionStep $step, NodeResult $result, Redactor $redactor): void
    {
        if ($result->items === []) {
            $step->wait_type = WaitType::Loop;
            $this->complete($step, ['count' => 0], [$result->donePort], $redactor);

            return;
        }

        $this->park($step, WaitType::Loop, null, [
            'items' => $result->items,
            'index' => 0,
            'body_port' => $result->bodyPort,
            'done_port' => $result->donePort,
        ], $redactor);

        $this->startIteration($step, 0);
    }

    protected function runChild(ExecutionStep $step, NodeResult $result, Redactor $redactor): void
    {
        $this->park($step, WaitType::Child, null, ['workflow_id' => $result->childWorkflow?->id], $redactor);

        app(Engine::class)->start(
            $result->childWorkflow,
            $result->childInput,
            triggerType: 'workflow',
            parentStep: $step,
        );
    }

    protected function startIteration(ExecutionStep $loopStep, int $index): void
    {
        $graph = $this->graphFor($loopStep->execution);
        $scope = Scope::forIteration($loopStep->scope_key, $loopStep->id, $index);

        foreach ($graph->outgoing($loopStep->node_id, $loopStep->state['body_port'] ?? 'item') as $edge) {
            $this->enter($loopStep, $edge->target, $edge->id, $loopStep->state['body_port'] ?? 'item', $scope);
        }
    }

    /**
     * Create the next steps along every edge leaving $port.
     */
    protected function follow(ExecutionStep $step, string $port): void
    {
        foreach ($this->graphFor($step->execution)->outgoing($step->node_id, $port) as $edge) {
            $this->enter($step, $edge->target, $edge->id, $port, $step->scope_key);
        }
    }

    protected function enter(ExecutionStep $from, string $targetNodeId, string $edgeId, string $port, string $scope): void
    {
        $execution = $from->execution;
        $target = $this->graphFor($execution)->node($targetNodeId);

        if (! $target->waitForAll && ! $this->registry->make($target->type)->joinsBranches()) {
            $this->createStep($execution, $targetNodeId, $scope, $from, $port);

            return;
        }

        $join = $execution->steps()
            ->where('node_id', $targetNodeId)
            ->where('scope_key', $scope)
            ->where('status', StepStatus::Waiting)
            ->where('wait_type', WaitType::Join)
            ->first();

        if (! $join) {
            $join = $this->createStep($execution, $targetNodeId, $scope, $from, $port, status: StepStatus::Waiting, waitType: WaitType::Join, state: ['arrivals' => []]);
        }

        $state = $join->state ?? [];
        $state['arrivals'][$edgeId] = ['step_id' => $from->id, 'node_id' => $from->node_id, 'port' => $port];
        $join->forceFill(['state' => $state])->save();
    }

    /**
     * @param  array<string, mixed>|null  $state
     */
    protected function createStep(
        Execution $execution,
        string $nodeId,
        string $scope,
        ?ExecutionStep $previous,
        ?string $entryPort,
        int $attempt = 1,
        ?ExecutionStep $retryOf = null,
        ?CarbonInterface $availableAt = null,
        StepStatus $status = StepStatus::Pending,
        ?WaitType $waitType = null,
        ?array $state = null,
    ): ExecutionStep {
        $node = $this->graphFor($execution)->node($nodeId);

        $step = $execution->steps()->create([
            'node_id' => $nodeId,
            'node_type' => $node->type,
            'attempt' => $attempt,
            'retry_of_id' => $retryOf?->id,
            'previous_step_id' => $previous?->id,
            'entry_port' => $entryPort,
            'scope_key' => $scope,
            'status' => $status,
            'wait_type' => $waitType,
            'state' => $state,
            'available_at' => $availableAt ?? now(),
        ]);

        $step->setRelation('execution', $execution);

        if ($status === StepStatus::Pending) {
            $this->dispatchRun($step);
        }

        return $step;
    }

    /**
     * After any change: finish loop iterations whose steps are all done,
     * release merges that have everything they will get, then derive the
     * execution's status from its open steps.
     */
    protected function settle(Execution $execution): void
    {
        for ($pass = 0; $pass < 1000 && ! $execution->status->isFinished(); $pass++) {
            if ($this->advanceLoops($execution) || $this->releaseJoins($execution)) {
                continue;
            }

            break;
        }

        if ($execution->status->isFinished()) {
            return;
        }

        $open = $execution->openSteps()->pluck('status')->all();

        if ($open === []) {
            $this->finish($execution, ExecutionStatus::Complete, output: $this->leafOutputs($execution));

            return;
        }

        $status = array_any($open, fn (StepStatus $status) => $status !== StepStatus::Waiting)
            ? ExecutionStatus::Running
            : ExecutionStatus::Waiting;

        if ($execution->status !== $status) {
            $execution->forceFill(['status' => $status])->save();
        }
    }

    /**
     * Move every loop whose current iteration has no open steps left to its
     * next item, or complete it after the last one.
     */
    protected function advanceLoops(Execution $execution): bool
    {
        $advanced = false;

        $loops = $execution->steps()
            ->where('status', StepStatus::Waiting)
            ->where('wait_type', WaitType::Loop)
            ->get();

        foreach ($loops as $loop) {
            $loop->setRelation('execution', $execution);
            $state = $loop->state ?? [];
            $index = $state['index'] ?? 0;
            $scope = Scope::forIteration($loop->scope_key, $loop->id, $index);

            if ($this->hasOpenStepsIn($execution, $scope)) {
                continue;
            }

            $advanced = true;

            if ($index + 1 < count($state['items'] ?? [])) {
                $loop->forceFill(['state' => [...$state, 'index' => $index + 1]])->save();
                $this->startIteration($loop, $index + 1);

                continue;
            }

            $loop->forceFill([
                'status' => StepStatus::Complete,
                'output' => ['count' => $index + 1],
                'output_ports' => [$state['done_port'] ?? 'done'],
                'finished_at' => now(),
            ])->save();

            $this->follow($loop, $state['done_port'] ?? 'done');
        }

        return $advanced;
    }

    /**
     * A merge, or a node set to wait for all its incoming branches, runs once
     * every incoming connection has arrived, or once no open step can still
     * reach it (the other paths were never taken).
     */
    protected function releaseJoins(Execution $execution): bool
    {
        $released = false;
        $graph = $this->graphFor($execution);

        $joins = $execution->steps()
            ->where('status', StepStatus::Waiting)
            ->where('wait_type', WaitType::Join)
            ->get();

        foreach ($joins as $join) {
            $arrived = array_keys($join->state['arrivals'] ?? []);
            $expected = array_map(fn ($edge) => $edge->id, $graph->incoming($join->node_id));
            $complete = array_diff($expected, $arrived) === [];

            if (! $complete) {
                $stillComing = $execution->openSteps()
                    ->whereKeyNot($join->id)
                    ->get(['id', 'node_id', 'scope_key', 'wait_type'])
                    ->contains(fn (ExecutionStep $open) => $open->wait_type !== WaitType::Join
                        && Scope::contains($join->scope_key, $open->scope_key)
                        && $graph->canReach($open->node_id, $join->node_id));

                if ($stillComing) {
                    continue;
                }
            }

            // Only nodes built to join read who arrived; any other node set
            // to wait for its branches starts with a clean state.
            $state = $this->registry->make($join->node_type)->joinsBranches() ? $join->state : null;

            $join->forceFill(['status' => StepStatus::Pending, 'wait_type' => null, 'state' => $state, 'available_at' => now()])->save();
            $this->dispatchRun($join);
            $released = true;
        }

        return $released;
    }

    protected function hasOpenStepsIn(Execution $execution, string $scope): bool
    {
        return $execution->openSteps()
            ->where(fn ($query) => $query->where('scope_key', $scope)->orWhere('scope_key', 'like', $scope.'/%'))
            ->exists();
    }

    /**
     * The run's result: the completed top-level steps the run had nowhere to
     * continue from, keyed by node id, each in the same shape as nodes.<id>.
     * A step continues when a connection leaves an output it took, which
     * also holds for every branch that meets at a join.
     *
     * @return array<string, array{output: mixed, port: string|null}>
     */
    protected function leafOutputs(Execution $execution): array
    {
        $graph = $this->graphFor($execution);
        $steps = $execution->steps()->where('scope_key', '')->orderBy('id')->get(['id', 'node_id', 'scope_key', 'status', 'wait_type', 'output', 'output_ports']);
        $results = LoopResults::for($execution);

        return $steps
            ->filter(fn (ExecutionStep $step) => $step->status === StepStatus::Complete
                && ! array_any($step->output_ports ?? [], fn (string $port) => $graph->outgoing($step->node_id, $port) !== []))
            ->mapWithKeys(fn (ExecutionStep $step) => [$step->node_id => $results->entry($step)])
            ->all();
    }
}
