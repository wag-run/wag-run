<?php

namespace Tests\Feature\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\Secret;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class SecretsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_a_secret_that_is_encrypted_at_rest(): void
    {
        $workspace = Workspace::factory()->create();
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();

        Livewire::actingAs($admin)
            ->test('pages::workspaces.secrets', ['workspace' => $workspace])
            ->set('key', 'stripe_key')
            ->set('value', 'sk_live_plaintext')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('value', '');

        $stored = DB::table('secrets')->where('key', 'stripe_key')->value('value');

        $this->assertNotSame('sk_live_plaintext', $stored);
        $this->assertSame('sk_live_plaintext', Crypt::decryptString($stored));
        $this->assertDatabaseHas('audit_logs', ['action' => 'secret.created', 'subject_label' => 'stripe_key']);
    }

    public function test_page_never_renders_secret_values(): void
    {
        $workspace = Workspace::factory()->create();
        Secret::factory()->for($workspace)->create(['key' => 'db_password', 'value' => 'hunter2-plaintext']);
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();

        $this->actingAs($admin)
            ->get(route('workspaces.secrets', $workspace))
            ->assertOk()
            ->assertSee('db_password')
            ->assertDontSee('hunter2-plaintext');
    }

    public function test_admin_reveal_returns_the_value_and_is_audited(): void
    {
        $workspace = Workspace::factory()->create();
        $secret = Secret::factory()->for($workspace)->create(['value' => 'revealed-value']);
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();

        Livewire::actingAs($admin)
            ->test('pages::workspaces.secrets', ['workspace' => $workspace])
            ->call('reveal', $secret->id)
            ->assertReturned('revealed-value');

        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'user_id' => $admin->id,
            'action' => 'secret.revealed',
            'subject_id' => $secret->id,
        ]);
    }

    public function test_org_admin_reveal_is_audited_against_the_workspace(): void
    {
        $workspace = Workspace::factory()->create();
        $secret = Secret::factory()->for($workspace)->create();
        $orgAdmin = User::factory()->orgAdmin()->create();

        Livewire::actingAs($orgAdmin)
            ->test('pages::workspaces.secrets', ['workspace' => $workspace])
            ->call('reveal', $secret->id)
            ->assertReturned($secret->value);

        $this->assertDatabaseHas('audit_logs', ['workspace_id' => $workspace->id, 'user_id' => $orgAdmin->id, 'action' => 'secret.revealed']);
    }

    public function test_editor_cannot_reveal_or_manage_secrets(): void
    {
        $workspace = Workspace::factory()->create();
        $secret = Secret::factory()->for($workspace)->create();
        $editor = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();

        Livewire::actingAs($editor)
            ->test('pages::workspaces.secrets', ['workspace' => $workspace])
            ->call('reveal', $secret->id)
            ->assertForbidden();

        Livewire::actingAs($editor)
            ->test('pages::workspaces.secrets', ['workspace' => $workspace])
            ->call('delete', $secret->id)
            ->assertForbidden();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'secret.revealed']);
        $this->assertModelExists($secret);
    }

    public function test_editing_without_a_new_value_keeps_the_current_value(): void
    {
        $workspace = Workspace::factory()->create();
        $secret = Secret::factory()->for($workspace)->create(['key' => 'token', 'value' => 'original']);
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();

        Livewire::actingAs($admin)
            ->test('pages::workspaces.secrets', ['workspace' => $workspace])
            ->call('edit', $secret->id)
            ->assertSet('value', '')
            ->set('description', 'Rotated quarterly')
            ->call('save')
            ->assertHasNoErrors();

        $secret->refresh();
        $this->assertSame('original', $secret->value);
        $this->assertSame('Rotated quarterly', $secret->description);
    }

    public function test_secret_values_are_hidden_from_serialization(): void
    {
        $secret = Secret::factory()->create(['value' => 'never-serialized']);

        $this->assertArrayNotHasKey('value', $secret->toArray());
    }
}
