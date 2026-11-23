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
#[Description('Get one workflow, including the inputs a run of it takes.')]
class GetWorkflow extends WorkflowApiTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'workflow' => $schema->string()->description('The workflow id.')->required(),
        ];
    }

    protected function call(Request $request, WorkflowApi $api, User $user): JsonResource
    {
        return new WorkflowResource($api->workflow($user, $this->workspace($request, $api, $user), (string) $request->get('workflow')));
    }
}
