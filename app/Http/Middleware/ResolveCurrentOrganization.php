<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Works out which organization a signed-in user's request is about, and
 * remembers it as their current organization:
 *
 * 1. Under a workspace's URL, the workspace's organization, when they belong to it.
 * 2. Otherwise their current organization, when they still belong to it and
 *    aren't deactivated there.
 * 3. Otherwise the first organization they're active in, then any they belong to.
 *
 * Requests without a user are left empty, for code to set from what it's about.
 */
class ResolveCurrentOrganization
{
    public function __construct(protected CurrentOrganization $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->current->set(null);

        $user = $request->user();

        if ($user instanceof User) {
            $organization = $this->resolve($user, $request->route('workspace'));

            if ($organization) {
                $this->current->set($organization);

                if ($user->current_organization_id !== $organization->id) {
                    $user->forceFill(['current_organization_id' => $organization->id])->saveQuietly();
                }
            }
        }

        return $next($request);
    }

    protected function resolve(User $user, mixed $workspace): ?Organization
    {
        if ($workspace instanceof Workspace && ($organization = $user->organizations()->find($workspace->organization_id))) {
            return $organization;
        }

        if ($user->current_organization_id
            && ($organization = $user->organizations()->wherePivotNull('deactivated_at')->find($user->current_organization_id))) {
            return $organization;
        }

        return $user->organizations()->wherePivotNull('deactivated_at')->oldest('organization_user.created_at')->first()
            ?? $user->organizations()->oldest('organization_user.created_at')->first();
    }
}
