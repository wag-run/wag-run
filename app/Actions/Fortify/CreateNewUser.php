<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\InstallRole;
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
     * sets up the install's organization and becomes its install admin; later
     * users can register only while open registration is on.
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
                    'email' => __('Registration is by invitation only. Ask an admin to invite you.'),
                ]);
            }

            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            if ($organization) {
                $user->organizations()->attach($organization, ['role' => InstallRole::Member->value]);

                return $user;
            }

            $organization = Organization::create(['name' => config('app.name')]);
            $user->organizations()->attach($organization, ['role' => InstallRole::Admin->value]);

            $workspace = $this->workspaces->create($organization, __('Default'), $user);
            $user->forceFill(['current_workspace_id' => $workspace->id])->save();

            return $user;
        }));
    }
}
