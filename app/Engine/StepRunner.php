<?php

namespace App\Engine;

use App\Engine\Templates\TemplateEngine;
use App\Enums\ResumeReason;
use App\Enums\StepStatus;
use App\Enums\WaitType;
use App\Models\ExecutionStep;
use Closure;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs one step on a queue worker: claims the row, builds the node's
 * context, calls the node, and hands the result to the coordinator.
 */
class StepRunner
{
    public function __construct(
        protected Coordinator $coordinator,
        protected NodeRegistry $registry,
        protected ContextBuilder $contexts,
        protected TemplateEngine $templates,
    ) {}

    public function run(int $stepId): void
    {
        $token = (string) Str::uuid();

        $claimed = ExecutionStep::query()
            ->whereKey($stepId)
            ->where('status', StepStatus::Pending)
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->update([
                'status' => StepStatus::Running,
                'claim_token' => $token,
                'started_at' => now(),
                'heartbeat_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return;
        }

        $this->execute($stepId, $token, fn (Node $node, NodeContext $context) => $node->execute($context));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function resume(int $stepId, ResumeReason $reason, array $payload = []): void
    {
        $token = (string) Str::uuid();

        $waitTypes = match ($reason) {
            ResumeReason::TimerElapsed => [WaitType::Timer, WaitType::External],
            ResumeReason::External => [WaitType::External],
            ResumeReason::ChildCompleted, ResumeReason::ChildFailed => [WaitType::Child],
            ResumeReason::PassFinished => [WaitType::Loop],
        };

        // A loop is looked at once per finished pass: the coordinator sets
        // resume_at when a pass ends, and claiming the step clears it, so a
        // second job for the same pass finds nothing to claim.
        $passFinished = $reason === ResumeReason::PassFinished;

        $claimed = ExecutionStep::query()
            ->whereKey($stepId)
            ->where('status', StepStatus::Waiting)
            ->whereIn('wait_type', $waitTypes)
            ->when($reason === ResumeReason::TimerElapsed, fn ($query) => $query->where('resume_at', '<=', now()))
            ->when($passFinished, fn ($query) => $query->whereNotNull('resume_at'))
            ->update([
                'status' => StepStatus::Running,
                'claim_token' => $token,
                'heartbeat_at' => now(),
                'updated_at' => now(),
                ...($passFinished ? ['resume_at' => null] : []),
            ]);

        if ($claimed === 0) {
            return;
        }

        $resumption = new Resumption($reason, $payload);

        $this->execute($stepId, $token, fn (Node $node, NodeContext $context) => $node->resume($context, $resumption), insidePass: $passFinished);
    }

    /**
     * @param  Closure(Node, NodeContext): NodeResult  $call
     * @param  bool  $insidePass  for a loop looking at the pass it just finished: read the run's data as a step inside that pass does
     */
    protected function execute(int $stepId, string $token, Closure $call, bool $insidePass = false): void
    {
        $step = ExecutionStep::query()->with(['execution.workspace', 'execution.workflow', 'execution.version'])->findOrFail($stepId);
        $execution = $step->execution;

        if (! $this->registry->has($step->node_type)) {
            $this->coordinator->apply($step, $token, NodeResult::fail(__('engine.node_type_type_is_not', ['type' => $step->node_type]), retryable: false));

            return;
        }

        $node = $this->registry->make($step->node_type);
        $graphNode = $this->coordinator->graphFor($execution)->node($step->node_id);
        $scope = $insidePass ? Scope::forIteration($step->scope_key, $step->id, (int) ($step->state['index'] ?? 0)) : null;
        $context = new NodeContext($step, $execution, $graphNode, $node, $this->contexts->build($step, $scope, beforeFirstPass: $node->repeats() && ! $insidePass), $this->templates);

        try {
            $inputs = $context->inputs();
        } catch (Throwable $e) {
            $this->coordinator->apply($step, $token, NodeResult::fail(__('engine.could_not_resolve_inputs_message', ['message' => $e->getMessage()]), retryable: false));

            return;
        }

        $step->newQuery()->whereKey($step->id)->update([
            'input' => json_encode(Redactor::forWorkspace($execution->workspace)->mask($inputs)),
        ]);

        try {
            $result = $call($node, $context);
        } catch (Throwable $e) {
            report($e);
            $result = NodeResult::fail($e->getMessage());
        }

        $this->coordinator->apply($step, $token, $result);
    }
}
