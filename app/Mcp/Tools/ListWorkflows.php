<?php

namespace App\Mcp\Tools;

use App\Http\Resources\WorkflowResource;
use App\Models\User;
use App\Services\WorkflowApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the workflows in a workspace, with the inputs each one takes when you start a run.')]
class ListWorkflows extends WorkflowApiTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug, from list-workspaces.')->required(),
        ];
    }

    protected function call(Request $request, WorkflowApi $api, User $user): JsonResource
    {
        return WorkflowResource::collection($api->workflows($user, $this->workspace($request, $api, $user)));
    }
}
