<?php

namespace App\Http\Controllers;

use App\Engine\Editor\WorkflowExport;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class WorkflowExportController extends Controller
{
    public function __invoke(Workspace $workspace, Workflow $workflow): JsonResponse
    {
        return response()->json(WorkflowExport::export($workflow), 200, [
            'Content-Disposition' => 'attachment; filename="'.(Str::slug($workflow->name) ?: 'workflow').'.workflow.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
