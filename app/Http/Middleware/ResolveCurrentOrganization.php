<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the organization a signed-in user's request is about, see
 * {@see CurrentOrganization::forUser()}, and remembers it as their current
 * organization. Requests without a user are left empty.
 */
class ResolveCurrentOrganization
{
    public function __construct(protected CurrentOrganization $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->current->set(null);

        $user = $request->user();

        if ($user instanceof User) {
            $workspace = $request->route('workspace');
            $organization = $this->current->forUser($user, $workspace instanceof Workspace ? $workspace : null);

            if ($organization) {
                $this->current->set($organization);

                if ($user->current_organization_id !== $organization->id) {
                    $user->forceFill(['current_organization_id' => $organization->id])->saveQuietly();
                }
            }
        }

        return $next($request);
    }
}
