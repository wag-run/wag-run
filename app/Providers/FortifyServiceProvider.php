<?php

namespace App\Providers;

use App\Actions\Fortify\AskForTwoFactorCode;
use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Controllers\SignInEmailController;
use App\Http\Responses\PasswordResetLinkRequestedResponse;
use App\Models\Organization;
use App\Models\User;
use App\Services\SignInOptions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureResponses();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);

        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()->where('email', Str::lower((string) $request->input(Fortify::username())))->first();

            // Check the password even when there's no account, against a throwaway hash, so a wrong
            // email takes as long as a wrong password and the timing doesn't reveal who has an account.
            static $throwawayHash = null;
            $passwordMatches = Hash::check((string) $request->input('password'), $user->password ?? ($throwawayHash ??= Hash::make(Str::random(40))));

            if (! $user || ! $passwordMatches) {
                return null;
            }

            if ($user->organizations()->exists() && ! $user->hasActiveMembership()) {
                throw ValidationException::withMessages([Fortify::username() => __('auth.session.this_account_has_been_deactivated')]);
            }

            if ($user->organizations()->exists() && ! $user->organizations()->wherePivotNull('deactivated_at')->get()->contains(fn (Organization $organization) => $organization->password_sign_in)) {
                throw ValidationException::withMessages([Fortify::username() => __('auth.login.your_organization_signs_in_with')]);
            }

            return $user;
        });
    }

    /**
     * Answer every password reset link request the same way, so the form can't
     * be used to find out who has an account.
     */
    private function configureResponses(): void
    {
        $this->app->singleton(SuccessfulPasswordResetLinkRequestResponse::class, PasswordResetLinkRequestedResponse::class);
        $this->app->singleton(FailedPasswordResetLinkRequestResponse::class, PasswordResetLinkRequestedResponse::class);
        $this->app->singleton(RedirectsIfTwoFactorAuthenticatable::class, AskForTwoFactorCode::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(function (Request $request) {
            $email = $request->session()->get(SignInEmailController::SESSION_KEY);
            $options = $email ? app(SignInOptions::class)->forEmail($email) : null;

            // The only way in is one provider: go straight there, unless the page has something to say.
            if ($options && ! $options['passwordAllowed'] && $options['providers']->count() === 1
                && ! $request->session()->has('errors') && ! $request->session()->has('status')) {
                return redirect()->route('oidc.redirect', $options['providers']->first());
            }

            return view('pages::auth.login', [
                'email' => $email,
                'providers' => $options['providers'] ?? collect(),
                'claimingProvider' => $options['claimingProvider'] ?? null,
                'passwordAllowed' => $options['passwordAllowed'] ?? true,
            ]);
        });
        Fortify::verifyEmailView(fn () => view('pages::auth.verify-email'));
        Fortify::twoFactorChallengeView(fn () => view('pages::auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => redirect()->route('dashboard'));
        Fortify::registerView(fn () => Organization::query()->exists()
            ? redirect()->route('login')
            : view('pages::auth.register'));
        Fortify::resetPasswordView(fn () => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('pages::auth.forgot-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
