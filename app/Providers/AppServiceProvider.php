<?php

namespace App\Providers;

use App\Credentials\CredentialTypeRegistry;
use App\Credentials\Types\AiProvider;
use App\Credentials\Types\ApiToken;
use App\Credentials\Types\ChatWebhook;
use App\Credentials\Types\HttpBasic;
use App\Credentials\Types\S3Storage;
use App\Credentials\Types\Smtp;
use App\Enums\WorkspacePermission;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkflowFiles;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\DevCommands;
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
        $this->app->scoped(WorkflowFiles::class);

        $this->app->singleton(CredentialTypeRegistry::class, function () {
            $registry = new CredentialTypeRegistry;

            $registry->register(new HttpBasic);
            $registry->register(new ApiToken);
            $registry->register(new AiProvider);
            $registry->register(new Smtp);
            $registry->register(new ChatWebhook);
            $registry->register(new S3Storage);

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
        $this->configureDevCommands();
    }

    /**
     * `composer run dev` (php artisan dev): workflow steps run on their own
     * "workflows" queue, and waits, schedule triggers and log cleanup need
     * the scheduler, so listen on both queues and run the scheduler too.
     */
    protected function configureDevCommands(): void
    {
        DevCommands::artisan('queue:listen --queue=workflows,default --tries=1 --timeout=0', 'queue');
        DevCommands::artisan('schedule:work', 'scheduler');
    }

    /**
     * Define the org-admin gate and one gate per workspace permission, so
     * routes can use `can:manage-secrets,workspace` and components can call
     * `$this->authorize('manage-secrets', $workspace)`.
     */
    protected function configureAuthorization(): void
    {
        Gate::define('org-admin', fn (User $user): bool => $user->isOrgAdmin());

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
