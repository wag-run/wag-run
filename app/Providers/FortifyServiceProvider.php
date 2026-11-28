<?php

namespace App\Providers;

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
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(function (Request $request) {
            $email = $request->session()->get(SignInEmailController::SESSION_KEY);
            $options = app(SignInOptions::class);

            return view('pages::auth.login', [
                'email' => $email,
                'providers' => $email ? $options->providersFor($email) : collect(),
                'claimingProvider' => $email ? $options->providerClaiming($email) : null,
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
