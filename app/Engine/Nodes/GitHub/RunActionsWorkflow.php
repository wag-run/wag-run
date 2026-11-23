<?php

namespace App\Engine\Nodes\GitHub;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\NodeContext;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Starts a GitHub Actions workflow that has a workflow_dispatch trigger.
 * GitHub doesn't say which run it started.
 */
class RunActionsWorkflow extends GitHubAction
{
    public static function type(): string
    {
        return 'github.run_actions_workflow';
    }

    public function label(): string
    {
        return __('nodes.github.run_actions_workflow');
    }

    public function description(): string
    {
        return __('nodes.github.run_actions_workflow_description');
    }

    public function icon(): string
    {
        return 'play';
    }

    protected function actionInputs(): array
    {
        return [
            new Field('workflow', __('nodes.github.actions_workflow'), Field::TEXT, required: true, templated: true, description: __('nodes.github.actions_workflow_file_help')),
            new Field('ref', __('nodes.github.branch_or_tag'), Field::TEXT, required: true, default: 'main', templated: true),
            new Field('inputs', __('nodes.github.workflow_inputs'), Field::KEY_VALUE, templated: true, description: __('nodes.github.workflow_inputs_help')),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('workflow', __('nodes.github.actions_workflow'), 'string'),
            new Output('ref', __('nodes.github.branch_or_tag'), 'string'),
        ];
    }

    protected function send(PendingRequest $github, string $repository, NodeContext $context): Response
    {
        $workflow = rawurlencode(basename(trim((string) $context->input('workflow'))));
        $inputs = array_filter((array) $context->input('inputs', []), fn (mixed $value, mixed $key) => $key !== '', ARRAY_FILTER_USE_BOTH);

        return $github->post("/repos/{$repository}/actions/workflows/{$workflow}/dispatches", array_filter([
            'ref' => trim((string) $context->input('ref')),
            'inputs' => array_map(fn (mixed $value) => is_scalar($value) ? (string) $value : json_encode($value), $inputs) ?: null,
        ]));
    }

    protected function output(Response $response, string $repository, NodeContext $context): array
    {
        return ['workflow' => trim((string) $context->input('workflow')), 'ref' => trim((string) $context->input('ref'))];
    }
}
