<?php

namespace App\Mcp\Tools;

use App\Enums\ExecutionStatus;
use App\Http\Resources\ExecutionResource;
use App\Models\User;
use App\Services\WorkflowApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List a workspace\'s runs, newest first, 25 to a page. Optionally only one workflow\'s runs, or only runs with one status.')]
class ListRuns extends WorkflowApiTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'workflow' => $schema->string()->description('Only runs of this workflow id.'),
            'status' => $schema->string()->enum(array_column(ExecutionStatus::cases(), 'value'))->description('Only runs with this status.'),
            'page' => $schema->integer()->min(1)->description('The page, from 1.'),
        ];
    }

    protected function call(Request $request, WorkflowApi $api, User $user): JsonResource
    {
        return ExecutionResource::collection($api->runs($user, $this->workspace($request, $api, $user), $request->only(['workflow', 'status', 'page'])));
    }
}
