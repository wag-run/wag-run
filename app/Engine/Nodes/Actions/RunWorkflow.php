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
use App\Enums\NodeKind;
use App\Models\Workflow;

/**
 * Starts another workflow in the same workspace. Sync mode waits for it and
 * returns its outputs; if it fails, this node fails. Async mode starts it and
 * moves on. Trigger loops between workflows are not detected.
 */
class RunWorkflow extends Node
{
    public static function type(): string
    {
        return 'core.run_workflow';
    }

    public function label(): string
    {
        return __('Trigger other workflow');
    }

    public function description(): string
    {
        return __('Starts another workflow, and optionally waits for its outputs.');
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function category(): string
    {
        return __('Flow');
    }

    public function icon(): string
    {
        return 'play-circle';
    }

    public function inputs(): array
    {
        return [
            new Field('workflow', __('Workflow'), Field::WORKFLOW, required: true),
            new Field('mode', __('Mode'), Field::SELECT, required: true, default: 'sync', options: [
                'sync' => __('Wait for it to finish'),
                'async' => __('Start it and move on'),
            ]),
            new Field('input', __('Input'), Field::KEY_VALUE, templated: true, description: __('Values passed to the workflow\'s trigger.')),
        ];
    }

    public function outputs(array $config = []): array
    {
        return ($config['mode'] ?? 'sync') === 'async'
            ? [new Output('execution_id', __('Execution id'), 'number'), new Output('status', __('Status'), 'string')]
            : [new Output('output', __('Workflow output'), 'object', __('The output of the last node on each path, keyed by node id.'))];
    }

    public function execute(NodeContext $context): NodeResult
    {
        $workflow = Workflow::query()
            ->whereKey($context->input('workflow'))
            ->where('workspace_id', $context->workspace()->id)
            ->first();

        if (! $workflow) {
            return NodeResult::fail(__('The workflow to trigger does not exist in this workspace.'), retryable: false);
        }

        if (! $workflow->published_version_id) {
            return NodeResult::fail(__('Workflow ":name" has not been published.', ['name' => $workflow->name]), retryable: false);
        }

        $input = is_array($context->input('input')) ? $context->input('input') : [];

        if ($context->input('mode') === 'async') {
            $execution = app(Engine::class)->start($workflow, $input, triggerType: 'workflow');

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
