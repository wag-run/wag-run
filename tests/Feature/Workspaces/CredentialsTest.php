<?php

namespace Tests\Feature\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\Credential;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CredentialsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_an_ai_provider_key_encrypted_at_rest(): void
    {
        $workspace = Workspace::factory()->create();
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();

        Livewire::actingAs($admin)
            ->test('pages::workspaces.credentials', ['workspace' => $workspace])
            ->set('type', 'ai_provider')
            ->set('description', 'Anthropic production')
            ->set('config.provider', 'anthropic')
            ->set('config.api_key', 'sk-ant-plaintext')
            ->call('save')
            ->assertHasNoErrors();

        $credential = Credential::firstWhere('description', 'Anthropic production');

        $this->assertSame('ai_provider', $credential->type);
        $this->assertTrue(Str::isUuid($credential->id));
        $this->assertSame('sk-ant-plaintext', $credential->config['api_key']);
        $this->assertStringNotContainsString('sk-ant-plaintext', DB::table('credentials')->value('config'));
        $this->assertSame(['sk-ant-plaintext'], $credential->secretValues());
    }

    public function test_requires_each_field_of_the_chosen_type(): void
    {
        $workspace = Workspace::factory()->create();
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();

        Livewire::actingAs($admin)
            ->test('pages::workspaces.credentials', ['workspace' => $workspace])
            ->set('type', 'http_basic')
            ->set('description', 'Intranet')
            ->call('save')
            ->assertHasErrors(['config.username' => 'required', 'config.password' => 'required']);
    }

    public function test_the_description_is_required_and_unique_in_the_workspace(): void
    {
        $workspace = Workspace::factory()->create();
        Credential::factory()->for($workspace)->create(['description' => 'Billing API']);
        Credential::factory()->create(['description' => 'Shop API']);
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();

        $page = Livewire::actingAs($admin)->test('pages::workspaces.credentials', ['workspace' => $workspace])
            ->set('type', 'api_token')
            ->set('config.token', 'tok')
            ->call('save')
            ->assertHasErrors(['description' => 'required'])
            ->set('description', 'Billing API')
            ->call('save')
            ->assertHasErrors(['description' => 'unique'])
            ->set('description', 'Shop API')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['Billing API', 'Shop API'], $workspace->credentials()->orderBy('description')->pluck('description')->all(), 'another workspace may use the same description');
    }

    public function test_editing_without_new_secret_values_keeps_them(): void
    {
        $workspace = Workspace::factory()->create();
        $credential = Credential::factory()->for($workspace)->httpBasic()->create(['id' => $this->settingId('intranet'), 'description' => 'intranet']);
        $originalPassword = $credential->config['password'];
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();

        Livewire::actingAs($admin)
            ->test('pages::workspaces.credentials', ['workspace' => $workspace])
            ->call('edit', $credential->id)
            ->assertSet('config.password', '')
            ->set('config.username', 'svc-workflows')
            ->call('save')
            ->assertHasNoErrors();

        $credential->refresh();
        $this->assertSame('svc-workflows', $credential->config['username']);
        $this->assertSame($originalPassword, $credential->config['password']);
    }

    public function test_reveal_is_limited_to_secret_fields_and_audited(): void
    {
        $workspace = Workspace::factory()->create();
        $credential = Credential::factory()->for($workspace)->create();
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();

        $page = Livewire::actingAs($admin)->test('pages::workspaces.credentials', ['workspace' => $workspace]);

        $page->call('reveal', $credential->id, 'token')->assertReturned($credential->config['token']);
        $page->call('reveal', $credential->id, 'header')->assertNotFound();

        $this->assertDatabaseHas('audit_logs', ['action' => 'credential.revealed', 'subject_id' => $credential->id]);
    }

    public function test_editor_cannot_manage_authentication_settings(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();

        Livewire::actingAs($editor)
            ->test('pages::workspaces.credentials', ['workspace' => $workspace])
            ->set('description', 'nope')
            ->set('config.token', 'x')
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseCount('credentials', 0);
    }

    public function test_page_shows_setting_names_but_not_secret_values(): void
    {
        $workspace = Workspace::factory()->create();
        Credential::factory()->for($workspace)->create(['id' => $this->settingId('github_api'), 'description' => 'github_api', 'config' => ['header' => 'Authorization', 'prefix' => 'Bearer', 'token' => 'ghp_plaintext']]);
        $viewer = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();

        $this->actingAs($viewer)
            ->get(route('workspaces.credentials', $workspace))
            ->assertOk()
            ->assertSee('github_api')
            ->assertDontSee('ghp_plaintext');
    }
}
