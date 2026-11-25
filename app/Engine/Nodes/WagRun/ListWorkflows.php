<?php

namespace App\Engine\Nodes\WagRun;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\NodeContext;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * The workflows in one workspace, optionally each with its definition. The
 * workspace is in the output too, so steps inside a loop over the workflows
 * can still tell which workspace they came from.
 */
class ListWorkflows extends WagRunAction
{
    public static function type(): string
    {
        return 'wag_run.list_workflows';
    }

    public function label(): string
    {
        return __('nodes.wag_run.list_workflows');
    }

    public function description(): string
    {
        return __('nodes.wag_run.list_workflows_description', ['app' => config('app.name')]);
    }

    public function icon(): string
    {
        return 'queue-list';
    }

    protected function actionInputs(): array
    {
        return [
            new Field('workspace', __('common.workspace'), Field::TEXT, required: true, templated: true, description: __('nodes.wag_run.workspace_help')),
            new Field('include_definitions', __('nodes.wag_run.include_definitions'), Field::BOOLEAN, default: false, description: __('nodes.wag_run.include_definitions_help')),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('workflows', __('common.workflows'), 'array'),
            new Output('count', __('nodes.wag_run.how_many'), 'number'),
            new Output('workspace', __('nodes.wag_run.workspace_slug'), 'string'),
        ];
    }

    protected function send(PendingRequest $api, NodeContext $context): Response
    {
        $workspace = rawurlencode($this->segment($context, 'workspace', __('nodes.wag_run.workspace_is_empty')));

        return $api->get("/workspaces/{$workspace}/workflows", $context->input('include_definitions', false) ? ['include' => 'definition'] : []);
    }

    protected function output(Response $response, NodeContext $context): array
    {
        return [
            'workflows' => $response->json('data'),
            'count' => count($response->json('data')),
            'workspace' => trim((string) $context->input('workspace')),
        ];
    }
}
