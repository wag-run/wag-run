<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExecutionResource;
use App\Services\WorkflowApi;
use App\Support\RunChain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RunController extends Controller
{
    public function __construct(protected WorkflowApi $api) {}

    public function index(Request $request, string $workspace): AnonymousResourceCollection
    {
        $user = $request->user();

        return ExecutionResource::collection($this->api->runs($user, $this->api->workspace($user, $workspace), $request->query()));
    }

    /**
     * Start a run. The body's "input" holds values for the workflow's
     * Manual trigger fields.
     */
    public function store(Request $request, string $workspace, string $workflow): JsonResponse
    {
        $user = $request->user();
        $execution = $this->api->startRun($user, $this->api->workspace($user, $workspace), $workflow, (array) $request->input('input', []), RunChain::from($request));

        return (new ExecutionResource($execution))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $workspace, string $run): ExecutionResource
    {
        $user = $request->user();

        return new ExecutionResource($this->api->run($user, $this->api->workspace($user, $workspace), $run));
    }

    public function cancel(Request $request, string $workspace, string $run): ExecutionResource
    {
        $user = $request->user();

        return new ExecutionResource($this->api->cancelRun($user, $this->api->workspace($user, $workspace), $run));
    }
}
