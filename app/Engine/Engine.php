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
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * The engine's public API: start, cancel, and resume runs.
 */
class Engine
{
    public function __construct(
        protected Coordinator $coordinator,
        protected NodeRegistry $registry,
    ) {}

    /**
     * Start a run of the workflow's published version. Every workflow can be
     * started manually; without a trigger node id, the run starts at its
     * first trigger with $input as that trigger's payload.
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
    ): Execution {
        $version = $workflow->publishedVersion
            ?? throw new RuntimeException(__('Workflow ":name" has no published version.', ['name' => $workflow->name]));

        $graph = $version->toGraph();
        $triggerNodeId ??= $this->firstTrigger($graph->nodes);

        if ($triggerNodeId === null || ! $graph->has($triggerNodeId)) {
            throw new InvalidArgumentException(__('Workflow ":name" has no trigger to start from.', ['name' => $workflow->name]));
        }

        return DB::transaction(function () use ($workflow, $version, $input, $user, $triggerType, $triggerNodeId, $parentStep) {
            Workflow::query()->lockForUpdate()->find($workflow->id);

            $inProgress = $workflow->executions()->whereIn('status', ExecutionStatus::inProgress())->get();

            $attributes = [
                'workspace_id' => $workflow->workspace_id,
                'workflow_id' => $workflow->id,
                'workflow_version_id' => $version->id,
                'trigger_type' => $triggerType,
                'trigger_node_id' => $triggerNodeId,
                'input' => Redactor::forWorkspace($workflow->workspace)->mask($input),
                'triggered_by' => $user?->id,
                'parent_step_id' => $parentStep?->id,
                'started_at' => now(),
            ];

            if ($inProgress->isNotEmpty() && $workflow->concurrency === ConcurrencyPolicy::SkipIfRunning) {
                $skipped = Execution::create([...$attributes, 'status' => ExecutionStatus::Skipped, 'finished_at' => now()]);

                DB::afterCommit(fn () => event(new ExecutionFinished($skipped)));
                $this->coordinator->notifyParent($skipped);

                return $skipped;
            }

            if ($workflow->concurrency === ConcurrencyPolicy::CancelPrevious) {
                foreach ($inProgress as $previous) {
                    $this->coordinator->locked($previous->id, fn (Execution $locked) => $this->coordinator->finish($locked, ExecutionStatus::Cancelled));
                }
            }

            $execution = Execution::create([...$attributes, 'status' => ExecutionStatus::Running]);
            $this->coordinator->startAt($execution, $triggerNodeId);

            return $execution;
        });
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
            throw new InvalidArgumentException(__('This step is not waiting for a response.'));
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
