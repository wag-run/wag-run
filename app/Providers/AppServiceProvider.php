<?php

namespace App\Providers;

use App\Credentials\CredentialTypeRegistry;
use App\Credentials\Types\AiProvider;
use App\Credentials\Types\ApiToken;
use App\Credentials\Types\HttpBasic;
use App\Enums\WorkspacePermission;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CredentialTypeRegistry::class, function () {
            $registry = new CredentialTypeRegistry;

            $registry->register(new HttpBasic);
            $registry->register(new ApiToken);
            $registry->register(new AiProvider);

            return $registry;
        });

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
    }

    /**
     * Define the install-admin gate and one gate per workspace permission, so
     * routes can use `can:manage-secrets,workspace` and components can call
     * `$this->authorize('manage-secrets', $workspace)`.
     */
    protected function configureAuthorization(): void
    {
        Gate::define('install-admin', fn (User $user): bool => $user->isInstallAdmin());

        foreach (WorkspacePermission::cases() as $permission) {
            Gate::define(
                $permission->value,
                fn (User $user, Workspace $workspace): bool => $user->hasWorkspacePermission($workspace, $permission),
            );
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
