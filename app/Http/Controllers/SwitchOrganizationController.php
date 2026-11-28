<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Switches someone in several organizations into one of theirs, from the
 * workspace switcher. Opening one of its workspaces does the same; this is
 * for an organization where they can't open any.
 */
class SwitchOrganizationController extends Controller
{
    public function __invoke(Request $request, Organization $organization): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->isActiveIn($organization), 404);

        $user->forceFill(['current_organization_id' => $organization->id])->save();

        return redirect()->route('dashboard');
    }
}
