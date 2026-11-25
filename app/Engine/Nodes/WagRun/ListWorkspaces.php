<?php

namespace App\Engine\Nodes\WagRun;

use App\Engine\Definitions\Output;
use App\Engine\NodeContext;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * The workspaces the API token's person belongs to.
 */
class ListWorkspaces extends WagRunAction
{
    public static function type(): string
    {
        return 'wag_run.list_workspaces';
    }

    public function label(): string
    {
        return __('nodes.wag_run.list_workspaces');
    }

    public function description(): string
    {
        return __('nodes.wag_run.list_workspaces_description', ['app' => config('app.name')]);
    }

    public function icon(): string
    {
        return 'rectangle-stack';
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('workspaces', __('common.workspaces'), 'array'),
            new Output('count', __('nodes.wag_run.how_many'), 'number'),
        ];
    }

    protected function send(PendingRequest $api, NodeContext $context): Response
    {
        return $api->get('/workspaces');
    }

    protected function output(Response $response, NodeContext $context): array
    {
        return ['workspaces' => $response->json('data'), 'count' => count($response->json('data'))];
    }
}
