<?php

namespace App\Services;

use App\Enums\OrgRole;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * Organization-admin user management. The last active organization admin can never be
 * demoted, deactivated or deleted.
 */
class UserDirectory
{
    public function promote(User $user): void
    {
        $this->setRole($user, OrgRole::Admin);
        Audit::record('user.promoted', $user);
    }

    public function demote(User $user): void
    {
        $this->ensureNotLastAdmin($user, __('members.last_organization_admin_cannot_be_2'));
        $this->setRole($user, OrgRole::Member);
        Audit::record('user.demoted', $user);
    }

    public function deactivate(User $user, User $actor): void
    {
        if ($user->is($actor)) {
            throw ValidationException::withMessages(['user' => __('members.you_cannot_deactivate_your_own')]);
        }

        $this->ensureNotLastAdmin($user, __('members.last_organization_admin_cannot_be'));

        $this->organization()->users()->updateExistingPivot($user->id, ['deactivated_at' => now()]);
        DB::table('sessions')->where('user_id', $user->id)->delete();

        Audit::record('user.deactivated', $user);
    }

    public function reactivate(User $user): void
    {
        $this->organization()->users()->updateExistingPivot($user->id, ['deactivated_at' => null]);

        Audit::record('user.reactivated', $user);
    }

    /**
     * A one-time password-reset link an organization admin can hand to the user.
     */
    public function passwordResetLink(User $user): string
    {
        $token = Password::createToken($user);

        Audit::record('user.password_reset_link_generated', $user);

        return route('password.reset', ['token' => $token, 'email' => $user->email]);
    }

    /**
     * @throws ValidationException
     */
    public function ensureNotLastAdmin(User $user, string $message): void
    {
        $organization = Organization::current();

        if ($organization && $user->isOrgAdmin() && $organization->activeAdminCount() <= 1) {
            throw ValidationException::withMessages(['user' => $message]);
        }
    }

    protected function setRole(User $user, OrgRole $role): void
    {
        $organization = $this->organization();

        if (! $user->organizations()->whereKey($organization->id)->exists()) {
            $user->organizations()->attach($organization, ['role' => $role->value]);

            return;
        }

        $organization->users()->updateExistingPivot($user->id, ['role' => $role->value]);
    }

    protected function organization(): Organization
    {
        return Organization::current() ?? throw new \RuntimeException('The install has no organization yet.');
    }
}
