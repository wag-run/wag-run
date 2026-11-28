<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;

/**
 * The organization the current request or job is about. Middleware sets it for
 * web and API requests, code that runs without a user (a job, a webhook) works
 * from the record it handles instead, and anything else signed in gets the
 * user's own organization. It's scoped, so it starts empty for every request
 * and every queued job, and it never falls back to some other organization.
 *
 * Read it through {@see Organization::current()}.
 */
class CurrentOrganization
{
    protected ?Organization $organization = null;

    public function set(?Organization $organization): void
    {
        $this->organization = $organization;
    }

    /**
     * The organization set for this request or job, otherwise the signed-in
     * user's, worked out afresh each time so it never goes stale.
     */
    public function get(): ?Organization
    {
        if ($this->organization) {
            return $this->organization;
        }

        $user = Auth::user();

        return $user instanceof User ? $this->forUser($user) : null;
    }

    /**
     * Which organization a user's request is about:
     *
     * 1. Under a workspace's URL, the workspace's organization, when they belong to it.
     * 2. Otherwise their current organization, when they still belong to it and
     *    aren't deactivated there.
     * 3. Otherwise the first organization they're active in, then any they belong to.
     */
    public function forUser(User $user, ?Workspace $workspace = null): ?Organization
    {
        if ($workspace && ($organization = $user->organizations()->find($workspace->organization_id))) {
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
