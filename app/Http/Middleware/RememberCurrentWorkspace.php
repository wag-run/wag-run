<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remembers the workspace the user last opened, so the dashboard returns to it.
 */
class RememberCurrentWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        $workspace = $request->route('workspace');

        if ($user instanceof User && $workspace instanceof Workspace
            && $response->isSuccessful()
            && $user->current_workspace_id !== $workspace->id) {
            $user->forceFill(['current_workspace_id' => $workspace->id])->saveQuietly();
        }

        return $response;
    }
}
