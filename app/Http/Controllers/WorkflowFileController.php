<?php

namespace App\Http\Controllers;

use App\Models\Execution;
use App\Models\WorkflowFile;
use App\Models\Workspace;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkflowFileController extends Controller
{
    /**
     * Download a file a run made or received. It is always sent as an
     * attachment, never shown inline, so an uploaded HTML or SVG file cannot
     * run in the app's pages.
     */
    public function download(Workspace $workspace, Execution $execution, WorkflowFile $file): StreamedResponse
    {
        abort_unless($execution->workspace_id === $workspace->id && $file->workspace_id === $workspace->id, 404);

        return Storage::disk($file->disk)->download($file->path, $file->name, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
        ]);
    }
}
