<?php

namespace App\Engine\Nodes\WagRun;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\NodeContext;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * One workflow, with its inputs and its definition.
 */
class GetWorkflow extends WagRunAction
{
    public static function type(): string
    {
        return 'wag_run.get_workflow';
    }

    public function label(): string
    {
        return __('nodes.wag_run.get_workflow');
    }

    public function description(): string
    {
        return __('nodes.wag_run.get_workflow_description', ['app' => config('app.name')]);
    }

    public function icon(): string
    {
        return 'document-text';
    }

    protected function actionInputs(): array
    {
        return [
            new Field('workspace', __('common.workspace'), Field::TEXT, required: true, templated: true, description: __('nodes.wag_run.workspace_help')),
            new Field('workflow', __('nodes.wag_run.workflow_id'), Field::TEXT, required: true, templated: true, description: __('nodes.wag_run.workflow_help')),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('workflow', __('common.workflow'), 'object', __('nodes.wag_run.workflow_with_definition')),
            new Output('workspace', __('nodes.wag_run.workspace_slug'), 'string'),
        ];
    }

    protected function send(PendingRequest $api, NodeContext $context): Response
    {
        $workspace = rawurlencode($this->segment($context, 'workspace', __('nodes.wag_run.workspace_is_empty')));
        $workflow = rawurlencode($this->segment($context, 'workflow', __('nodes.wag_run.workflow_is_empty')));

        return $api->get("/workspaces/{$workspace}/workflows/{$workflow}");
    }

    protected function output(Response $response, NodeContext $context): array
    {
        return ['workflow' => $response->json('data'), 'workspace' => trim((string) $context->input('workspace'))];
    }
}
