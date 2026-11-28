<?php

namespace Database\Seeders;

use App\Enums\OrgRole;
use App\Enums\WorkspaceRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed a local install: an organization admin and an editor, both signing in
     * with the factory password ("password"), a Default workspace and a few
     * published demo workflows.
     */
    public function run(): void
    {
        $organization = Organization::query()->oldest('id')->first() ?? Organization::factory()->create(['name' => config('app.name')]);

        $workspace = $organization->workspaces()->firstOrCreate(['slug' => 'default'], ['name' => 'Default']);

        $admin = User::query()->firstWhere('email', 'test@example.com')
            ?? User::factory()->create(['name' => 'Test Admin', 'email' => 'test@example.com']);

        $editor = User::query()->firstWhere('email', 'editor@example.com')
            ?? User::factory()->create(['name' => 'Test Editor', 'email' => 'editor@example.com']);

        $organization->users()->syncWithoutDetaching([
            $admin->id => ['role' => OrgRole::Admin->value],
            $editor->id => ['role' => OrgRole::Member->value],
        ]);

        $workspace->members()->syncWithoutDetaching([
            $editor->id => ['role' => WorkspaceRole::Editor->value],
        ]);

        $this->call(DemoWorkflowSeeder::class);
    }
}
