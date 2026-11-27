<?php

namespace Tests\Feature\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\Credential;
use App\Models\Secret;
use App\Models\StoreEntry;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Services\DataStore;
use App\Services\Invitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class WorkspaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Workspace $elsewhere;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        $this->elsewhere = Workspace::factory()->create();
        $this->admin = User::factory()->memberOf($this->workspace, WorkspaceRole::Admin)->create();
    }

    /**
     * A page of the admin's own workspace. Each refused call needs a new one,
     * since a 404 leaves the component unusable. $locked starts the page with
     * ids that are #[Locked], so only its own actions can set them, to reach
     * the lookups that saving repeats.
     *
     * @param  array<string, int|string>  $locked
     */
    private function page(string $name, array $locked = []): Testable
    {
        return Livewire::actingAs($this->admin)->test("pages::{$name}", ['workspace' => $this->workspace, ...$locked]);
    }

    public function test_the_secrets_page_cannot_see_or_change_another_workspaces_secrets(): void
    {
        Secret::factory()->for($this->workspace)->create(['key' => 'own_key']);
        $secret = Secret::factory()->for($this->elsewhere)->create(['key' => 'elsewhere_key', 'value' => 'elsewhere-value', 'description' => 'Theirs']);

        $this->page('workspaces.secrets')->assertSee('own_key')->assertDontSee('elsewhere_key');
        $this->page('workspaces.secrets')->call('edit', $secret->id)->assertNotFound();
        $this->page('workspaces.secrets')->call('reveal', $secret->id)->assertNotFound();
        $this->page('workspaces.secrets')->call('delete', $secret->id)->assertNotFound();
        $this->page('workspaces.secrets', ['editingId' => $secret->id])->set('key', 'elsewhere_key')->set('value', 'taken')->call('save')->assertNotFound();
        $this->page('workspaces.secrets')->set('key', 'elsewhere_key')->set('value', 'own-value')->call('save')->assertHasNoErrors();

        $secret->refresh();
        $this->assertSame([$this->elsewhere->id, 'elsewhere_key', 'elsewhere-value', 'Theirs'], [$secret->workspace_id, $secret->key, $secret->value, $secret->description]);
        $this->assertSame('own-value', $this->workspace->secrets()->where('key', 'elsewhere_key')->sole()->value);
        $this->assertSame(['secret.created'], AuditLog::pluck('action')->all());
    }

    public function test_the_authentication_page_cannot_see_or_change_another_workspaces_settings(): void
    {
        Credential::factory()->for($this->workspace)->create(['description' => 'Own API']);
        $credential = Credential::factory()->for($this->elsewhere)->create(['description' => 'Elsewhere API']);
        $config = $credential->config;

        $this->page('workspaces.credentials')->assertSee('Own API')->assertDontSee('Elsewhere API');
        $this->page('workspaces.credentials')->call('edit', $credential->id)->assertNotFound();
        $this->page('workspaces.credentials')->call('reveal', $credential->id, 'token')->assertNotFound();
        $this->page('workspaces.credentials')->call('delete', $credential->id)->assertNotFound();
        $this->page('workspaces.credentials', ['editingId' => $credential->id])->set('description', 'Taken')->set('config.token', 'taken')->call('save')->assertNotFound();

        $credential->refresh();
        $this->assertSame([$this->elsewhere->id, 'Elsewhere API', $config], [$credential->workspace_id, $credential->description, $credential->config]);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_the_data_store_page_cannot_see_or_change_another_workspaces_entries(): void
    {
        $own = StoreEntry::factory()->for($this->workspace)->collection()->holding(['T-1' => 'ours'])->create(['key' => 'own_items']);
        $entry = StoreEntry::factory()->for($this->elsewhere)->holding('theirs')->create(['key' => 'elsewhere_entry']);
        $items = StoreEntry::factory()->for($this->elsewhere)->collection()->holding(['T-9' => 'theirs'])->create(['key' => 'elsewhere_items']);
        $item = $items->values()->sole();

        $this->page('workspaces.data-store')->assertSee('own_items')->assertDontSee('elsewhere_entry')->assertDontSee('elsewhere_items');
        $this->page('workspaces.data-store')->call('edit', $entry->id)->assertNotFound();
        $this->page('workspaces.data-store', ['editingId' => $entry->id])->set('value', 'taken')->call('save')->assertNotFound();
        $this->page('workspaces.data-store')->call('toggleReadOnly', $entry->id)->assertNotFound();
        $this->page('workspaces.data-store')->call('delete', $entry->id)->assertNotFound();
        $this->page('workspaces.data-store')->call('browse', $items->id)->assertNotFound();
        $this->page('workspaces.data-store', ['browsingId' => $items->id])->set('itemKey', 'T-2')->set('itemValue', 'taken')->call('saveItem')->assertNotFound();
        $this->page('workspaces.data-store')->call('browse', $own->id)->call('editItem', $item->id)->assertNotFound();
        $this->page('workspaces.data-store')->call('browse', $own->id)->call('deleteItem', $item->id)->assertNotFound();
        $this->page('workspaces.data-store', ['browsingId' => $own->id, 'editingItemId' => $item->id])->set('itemValue', 'taken')->call('saveItem')->assertNotFound();

        $this->assertFalse($entry->refresh()->read_only);
        $this->assertSame('theirs', app(DataStore::class)->read($entry));
        $this->assertSame(['T-9' => 'theirs'], app(DataStore::class)->read($items->refresh()));
        $this->assertSame(['T-1' => 'ours'], app(DataStore::class)->read($own->refresh()));
        $this->assertSame(0, AuditLog::count());
    }

    public function test_the_members_page_cannot_see_or_change_another_workspaces_members_and_invitations(): void
    {
        $outsider = User::factory()->memberOf($this->elsewhere, WorkspaceRole::Viewer)->create(['email' => 'outsider@example.com']);
        Notification::fake();
        app(Invitations::class)->invite($this->workspace->organization, 'invited-here@example.com', $this->admin, workspace: $this->workspace);
        [$invitation] = app(Invitations::class)->invite($this->elsewhere->organization, 'invited-elsewhere@example.com', $outsider, workspace: $this->elsewhere);

        $this->page('workspaces.members')
            ->assertSee($this->admin->email)->assertDontSee('outsider@example.com')
            ->assertSee('invited-here@example.com')->assertDontSee('invited-elsewhere@example.com');
        $this->page('workspaces.members')->call('changeRole', $outsider->id, 'admin')->assertNotFound();
        $this->page('workspaces.members')->call('remove', $outsider->id)->assertNotFound();
        $this->page('workspaces.members')->call('revoke', $invitation->id)->assertNotFound();

        $this->assertSame(WorkspaceRole::Viewer, $outsider->roleIn($this->elsewhere));
        $this->assertNull($outsider->roleIn($this->workspace));
        $this->assertModelExists($invitation);
        $this->assertSame(['invitation.sent', 'invitation.sent'], AuditLog::pluck('action')->all());
    }

    public function test_the_workflows_page_cannot_see_or_delete_another_workspaces_workflows(): void
    {
        Workflow::factory()->for($this->workspace)->create(['name' => 'Own flow']);
        $workflow = Workflow::factory()->for($this->elsewhere)->create(['name' => 'Elsewhere flow']);

        $this->page('workflows.index')->assertSee('Own flow')->assertDontSee('Elsewhere flow');
        $this->page('workflows.index')->call('delete', $workflow->id)->assertNotFound();

        $this->assertModelExists($workflow);
        $this->assertSame(0, AuditLog::count());
    }
}
