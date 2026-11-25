<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\OrgRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\WorkspaceManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(protected WorkspaceManager $workspaces) {}

    /**
     * Validate and create a newly registered user. The first user to register
     * sets up the install's organization and becomes its organization admin; later
     * users can register only while open registration is on.
     *
     * The first user's email address counts as confirmed: they are setting the
     * install up, usually before it can send email.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return Cache::lock('register-user', 10)->block(5, fn () => DB::transaction(function () use ($input) {
            $organization = Organization::current();

            if ($organization && ! $organization->registration_open) {
                throw ValidationException::withMessages([
                    'email' => __('auth.register.registration_is_by_invitation_only_2'),
                ]);
            }

            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            if ($organization) {
                $user->organizations()->attach($organization, ['role' => OrgRole::Member->value]);

                return $user;
            }

            $organization = Organization::create(['name' => config('app.name')]);
            $user->organizations()->attach($organization, ['role' => OrgRole::Admin->value]);

            $workspace = $this->workspaces->create($organization, __('auth.register.default'), $user);
            $user->forceFill(['current_workspace_id' => $workspace->id, 'email_verified_at' => now()])->save();

            return $user;
        }));
    }
}
