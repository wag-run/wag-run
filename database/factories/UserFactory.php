<?php

namespace Database\Factories;

use App\Enums\OrgRole;
use App\Enums\WorkspaceRole;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * Make the user an admin of the install's organization.
     */
    public function orgAdmin(): static
    {
        return $this->afterCreating(fn (User $user) => static::joinOrganization($user, OrgRole::Admin));
    }

    /**
     * Make the user a member of the install's organization.
     */
    public function orgMember(): static
    {
        return $this->afterCreating(fn (User $user) => static::joinOrganization($user, OrgRole::Member));
    }

    /**
     * Make the user an organization member who has been deactivated.
     */
    public function deactivated(): static
    {
        return $this->afterCreating(function (User $user) {
            static::joinOrganization($user, OrgRole::Member);
            $user->organizations()->updateExistingPivot(Organization::current()->id, ['deactivated_at' => now()]);
        });
    }

    /**
     * Add the user to a workspace with a role (and to its organization as a member).
     */
    public function memberOf(Workspace $workspace, WorkspaceRole $role): static
    {
        return $this->afterCreating(function (User $user) use ($workspace, $role) {
            static::joinOrganization($user, OrgRole::Member, $workspace->organization);
            $user->workspaces()->attach($workspace, ['role' => $role->value]);
        });
    }

    protected static function joinOrganization(User $user, OrgRole $role, ?Organization $organization = null): void
    {
        $organization ??= Organization::current() ?? Organization::factory()->create();

        if (! $user->organizations()->whereKey($organization->id)->exists()) {
            $user->organizations()->attach($organization, ['role' => $role->value]);
        }
    }
}
