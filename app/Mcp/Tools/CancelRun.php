<?php

namespace App\Mcp\Tools;

use App\Http\Resources\ExecutionResource;
use App\Models\User;
use App\Services\WorkflowApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Cancel a run that is still running or waiting. A finished run is left as it is.')]
class CancelRun extends WorkflowApiTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'run' => $schema->string()->description('The run id.')->required(),
        ];
    }

    protected function call(Request $request, WorkflowApi $api, User $user): JsonResource
    {
        return new ExecutionResource($api->cancelRun($user, $this->workspace($request, $api, $user), (string) $request->get('run')));
    }
}
