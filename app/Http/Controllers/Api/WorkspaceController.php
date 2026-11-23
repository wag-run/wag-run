<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkspaceResource;
use App\Services\WorkflowApi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WorkspaceController extends Controller
{
    public function index(Request $request, WorkflowApi $api): AnonymousResourceCollection
    {
        return WorkspaceResource::collection($api->workspaces($request->user()));
    }
}
