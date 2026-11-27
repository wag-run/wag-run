<?php

namespace App\Mcp\Tools;

use App\Http\Resources\ExecutionResource;
use App\Models\User;
use App\Services\WorkflowApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Start a run of a workflow\'s published version. A workflow whose enabled is false cannot be started. The run continues in the background: use get-run to follow it.')]
class StartRun extends WorkflowApiTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'workflow' => $schema->string()->description('The workflow id.')->required(),
            'input' => $schema->object()->description('Values for the workflow\'s inputs, keyed by input name, as listed by get-workflow.'),
        ];
    }

    protected function call(Request $request, WorkflowApi $api, User $user): JsonResource
    {
        return new ExecutionResource($api->startRun($user, $this->workspace($request, $api, $user), (string) $request->get('workflow'), (array) $request->get('input', [])));
    }
}
