<?php

namespace App\Engine\Nodes\Actions;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\Engine;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Engine\ResultKind;
use App\Engine\Resumption;
use App\Enums\ExecutionStatus;
use App\Enums\NodeKind;
use App\Models\Workflow;

/**
 * Starts another workflow in the same workspace. Sync mode waits for it and
 * returns its outputs; if it fails, this node fails. Async mode starts it and
 * moves on. A workflow cannot start itself, and chains of runs starting runs
 * stop at the organization's maximum chain depth.
 */
class RunWorkflow extends Node
{
    public static function type(): string
    {
        return 'core.run_workflow';
    }

    public function label(): string
    {
        return __('nodes.run_workflow.trigger_other_workflow');
    }

    public function description(): string
    {
        return __('nodes.run_workflow.starts_another_workflow_and_optionally');
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function category(): string
    {
        return __('common.flow');
    }

    public function icon(): string
    {
        return 'play-circle';
    }

    public function inputs(): array
    {
        return [
            new Field('workflow', __('common.workflow'), Field::WORKFLOW, required: true),
            new Field('mode', __('nodes.run_workflow.mode'), Field::SELECT, required: true, default: 'sync', options: [
                'sync' => __('nodes.run_workflow.wait_for_it_to_finish'),
                'async' => __('nodes.run_workflow.start_it_and_move_on'),
            ]),
            new Field('input', __('common.input'), Field::KEY_VALUE, templated: true, description: __('nodes.run_workflow.values_passed_to_the_workflow')),
        ];
    }

    public function outputs(array $config = []): array
    {
        return ($config['mode'] ?? 'sync') === 'async'
            ? [new Output('execution_id', __('common.execution_id'), 'number'), new Output('status', __('common.status'), 'string')]
            : [new Output('output', __('nodes.run_workflow.workflow_output'), 'object', __('nodes.run_workflow.output_of_the_last_node'))];
    }

    public function execute(NodeContext $context): NodeResult
    {
        $workflow = Workflow::query()
            ->whereKey($context->input('workflow'))
            ->where('workspace_id', $context->workspace()->id)
            ->first();

        if (! $workflow) {
            return NodeResult::fail(__('nodes.run_workflow.workflow_to_trigger_does_not'), retryable: false);
        }

        if ($workflow->id === $context->execution->workflow_id) {
            return NodeResult::fail(__('nodes.run_workflow.a_workflow_cannot_trigger_itself'), retryable: false);
        }

        if (! $workflow->published_version_id) {
            return NodeResult::fail(__('nodes.run_workflow.workflow_name_has_not_been', ['name' => $workflow->name]), retryable: false);
        }

        $input = is_array($context->input('input')) ? $context->input('input') : [];

        if ($context->input('mode') === 'async') {
            $execution = app(Engine::class)->start($workflow, $input, triggerType: 'workflow', startedBy: $context->execution);

            if ($execution->status === ExecutionStatus::Cancelled) {
                return NodeResult::fail((string) ($execution->error['message'] ?? __('engine.child_workflow_was_cancelled')), retryable: false);
            }

            return NodeResult::complete(['execution_id' => $execution->id, 'status' => $execution->status->value]);
        }

        return NodeResult::runChild($workflow, $input);
    }

    public function resume(NodeContext $context, Resumption $resumption): NodeResult
    {
        $result = parent::resume($context, $resumption);

        return $result->kind === ResultKind::Complete
            ? NodeResult::complete(['output' => $result->output, 'execution_id' => $resumption->payload['execution_id'] ?? null])
            : $result;
    }
}
