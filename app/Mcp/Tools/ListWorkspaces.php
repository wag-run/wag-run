<?php

namespace App\Mcp\Tools;

use App\Http\Resources\WorkspaceResource;
use App\Models\User;
use App\Services\WorkflowApi;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the workspaces you belong to. Other tools take a workspace by its slug.')]
class ListWorkspaces extends WorkflowApiTool
{
    protected function call(Request $request, WorkflowApi $api, User $user): JsonResource
    {
        return WorkspaceResource::collection($api->workspaces($user));
    }
}
