<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkflowResource;
use App\Services\WorkflowApi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WorkflowController extends Controller
{
    public function __construct(protected WorkflowApi $api) {}

    /**
     * The workspace's workflows, each with its definition when the query
     * has "include=definition".
     */
    public function index(Request $request, string $workspace): AnonymousResourceCollection
    {
        $user = $request->user();
        $workflows = WorkflowResource::collection($this->api->workflows($user, $this->api->workspace($user, $workspace)));

        $workflows->collection->each->withDefinition($request->query('include') === 'definition');

        return $workflows;
    }

    public function show(Request $request, string $workspace, string $workflow): WorkflowResource
    {
        $user = $request->user();

        return (new WorkflowResource($this->api->workflow($user, $this->api->workspace($user, $workspace), $workflow)))->withDefinition();
    }
}
