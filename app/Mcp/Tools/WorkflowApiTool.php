<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkflowApi;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * A tool that calls {@see WorkflowApi}, the same service the REST API uses,
 * and answers with the same JSON the REST API returns.
 *
 * Validation and permission errors become tool errors on their own; this
 * adds "not found" for a workspace, workflow or run that doesn't exist or
 * that the user can't see.
 */
abstract class WorkflowApiTool extends Tool
{
    abstract protected function call(Request $request, WorkflowApi $api, User $user): JsonResource;

    public function handle(Request $request, WorkflowApi $api): Response
    {
        /** @var User $user */
        $user = $request->user();

        try {
            return Response::json($this->call($request, $api, $user)->response()->getData(true));
        } catch (ModelNotFoundException $e) {
            return Response::error(__('api.not_found', ['what' => class_basename($e->getModel())]));
        }
    }

    protected function workspace(Request $request, WorkflowApi $api, User $user): Workspace
    {
        return $api->workspace($user, (string) $request->get('workspace'));
    }
}
