<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\OrgRole;
use App\Http\Controllers\SignInEmailController;
use App\Models\OidcDomain;
use App\Models\Organization;
use App\Models\User;
use App\Services\SignInOptions;
use App\Services\WorkspaceManager;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(protected WorkspaceManager $workspaces, protected SignInOptions $signInOptions) {}

    /**
     * Create an account with a new organization, which they become the admin
     * of, and their first workspace in it.
     *
     * A self-hosted install signs up only its first account: once its
     * organization exists, people join by invitation or through a sign-in
     * provider, so this refuses before validating anything, which would
     * otherwise reveal whether an email has an account. That first account's
     * email counts as confirmed: they are setting the install up, usually
     * before it can send email.
     *
     * The hosted version signs anyone up, except at a domain another
     * organization has verified: they are sent to sign in with its provider.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $selfHosted = (bool) config('app.self_hosted');

        if ($selfHosted) {
            $this->ensureNoOrganizationYet();
        }

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'organization' => ['nullable', 'string', 'max:100'],
        ])->validate();

        $this->sendDomainOwnersToSignIn($input['email']);

        return Cache::lock('register-user', 10)->block(5, fn () => DB::transaction(function () use ($input, $selfHosted) {
            if ($selfHosted) {
                $this->ensureNoOrganizationYet();
            }

            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $organization = Organization::create([
                'name' => trim($input['organization'] ?? '') ?: __('auth.register.name_s_organization', ['name' => $user->firstName()]),
            ]);
            $user->organizations()->attach($organization, ['role' => OrgRole::Admin->value]);

            $this->workspaces->createFirstFor($user, $organization);

            if ($selfHosted) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            return $user;
        }));
    }

    /**
     * An organization that has verified the email's domain speaks for people
     * there: they sign in with its provider rather than starting another.
     *
     * @throws HttpResponseException when the email's domain has an owner
     */
    protected function sendDomainOwnersToSignIn(string $email): void
    {
        $provider = $this->signInOptions->domainOwner($email);

        if (! $provider) {
            return;
        }

        session()->put(SignInEmailController::SESSION_KEY, Str::lower(trim($email)));
        session()->forget(SignInEmailController::ORGANIZATION_KEY);

        throw new HttpResponseException(redirect()->route('login')->with('status', __('auth.register.domain_signs_in_with', [
            'domain' => OidcDomain::ofEmail($email),
            'provider' => $provider->name,
        ])));
    }

    /**
     * @throws ValidationException once the install has an organization
     */
    protected function ensureNoOrganizationYet(): void
    {
        if (Organization::query()->exists()) {
            throw ValidationException::withMessages([
                'email' => __('auth.register.registration_is_by_invitation_only_2'),
            ]);
        }
    }
}
