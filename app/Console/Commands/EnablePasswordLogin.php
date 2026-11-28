<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Support\Audit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sign-in:enable-password-login {--organization= : The ID or name of one organization; every organization when left out}')]
#[Description('Turn password sign-in back on, for an admin locked out by a broken sign-in provider')]
class EnablePasswordLogin extends Command
{
    public function handle(): int
    {
        $name = $this->option('organization');

        $organizations = Organization::query()
            ->when($name, fn ($query) => $query->whereKey($name)->orWhere('name', $name))
            ->get();

        if ($organizations->isEmpty()) {
            $this->components->error($name ? "No organization is called or has the ID [{$name}]." : 'There are no organizations yet.');

            return self::FAILURE;
        }

        foreach ($organizations as $organization) {
            if ($organization->password_sign_in) {
                $this->components->info("Password sign-in is already on for {$organization->name}.");

                continue;
            }

            $organization->password_sign_in = true;
            $organization->save();
            Audit::record('organization.settings_updated', $organization, metadata: ['changed' => ['password_sign_in'], 'via' => 'console'], organization: $organization);

            $this->components->info("Password sign-in is back on for {$organization->name}.");
        }

        return self::SUCCESS;
    }
}
