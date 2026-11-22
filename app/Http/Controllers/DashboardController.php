<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Send the user to the workspace they last used, or the first one they can
     * open. Users without any workspace see an empty state.
     */
    public function __invoke(Request $request): RedirectResponse|View
    {
        /** @var User $user */
        $user = $request->user();
        $workspaces = $user->accessibleWorkspaces();

        $workspace = $workspaces->firstWhere('id', $user->current_workspace_id) ?? $workspaces->first();

        if ($workspace) {
            return redirect()->route('workspaces.show', $workspace);
        }

        return view('dashboard', ['isOrgAdmin' => $user->isOrgAdmin()]);
    }
}
