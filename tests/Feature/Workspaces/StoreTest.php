<?php

namespace Tests\Feature\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\StoreEntry;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StoreTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
    }

    private function user(WorkspaceRole $role): User
    {
        return User::factory()->memberOf($this->workspace, $role)->create();
    }

    public function test_an_editor_sees_the_live_values_with_who_they_are_shared_with(): void
    {
        $workflow = Workflow::factory()->for($this->workspace)->create(['name' => 'Nightly sync']);
        StoreEntry::factory()->for($this->workspace)->create(['key' => 'ticket-1', 'value' => json_encode(['id' => 1])]);
        StoreEntry::factory()->for($this->workspace)->create(['key' => 'cursor', 'namespace' => $workflow->id, 'value' => '"abc"']);
        StoreEntry::factory()->for($this->workspace)->create(['key' => 'orphaned', 'namespace' => '0f1e2d3c-4b5a-4968-8776-655443322110']);
        StoreEntry::factory()->for($this->workspace)->expired()->create(['key' => 'expired-key']);
        StoreEntry::factory()->create(['key' => 'other-workspace']);

        $this->actingAs($this->user(WorkspaceRole::Editor))->get(route('workspaces.store', $this->workspace))
            ->assertOk()
            ->assertSeeInOrder(['cursor', 'Nightly sync', 'orphaned', 'Deleted workflow', 'ticket-1', '{&quot;id&quot;:1}', 'The workspace'], false)
            ->assertDontSee('expired-key')
            ->assertDontSee('other-workspace');
    }

    public function test_the_page_is_for_editors_and_admins_and_shows_in_their_sidebar_only(): void
    {
        $this->actingAs($this->user(WorkspaceRole::Viewer))->get(route('workspaces.store', $this->workspace))->assertForbidden();
        $this->actingAs($this->user(WorkspaceRole::Guest))->get(route('workspaces.store', $this->workspace))->assertForbidden();

        $this->actingAs($this->user(WorkspaceRole::Admin))->get(route('workspaces.store', $this->workspace))->assertOk();
        $this->actingAs($this->user(WorkspaceRole::Editor))->get(route('workspaces.show', $this->workspace))
            ->assertSee(route('workspaces.store', $this->workspace), false);
        $this->actingAs($this->user(WorkspaceRole::Viewer))->get(route('workspaces.show', $this->workspace))
            ->assertDontSee(route('workspaces.store', $this->workspace), false);
    }

    public function test_searching_narrows_the_list_by_key_and_treats_wildcards_as_text(): void
    {
        StoreEntry::factory()->for($this->workspace)->create(['key' => 'order-100']);
        StoreEntry::factory()->for($this->workspace)->create(['key' => 'order_200']);
        StoreEntry::factory()->for($this->workspace)->create(['key' => 'invoice-1']);

        Livewire::actingAs($this->user(WorkspaceRole::Editor))
            ->test('pages::workspaces.store', ['workspace' => $this->workspace])
            ->assertSee('invoice-1')
            ->set('search', 'order')
            ->assertSee('order-100')->assertSee('order_200')->assertDontSee('invoice-1')
            ->set('search', 'order_')
            ->assertSee('order_200')->assertDontSee('order-100')
            ->set('search', 'nothing like this')
            ->assertSee('No values match.');
    }

    public function test_an_editor_deletes_one_value_and_it_is_audited(): void
    {
        $editor = $this->user(WorkspaceRole::Editor);
        $gone = StoreEntry::factory()->for($this->workspace)->create(['key' => 'gone']);
        StoreEntry::factory()->for($this->workspace)->create(['key' => 'stays']);

        Livewire::actingAs($editor)
            ->test('pages::workspaces.store', ['workspace' => $this->workspace])
            ->call('delete', $gone->id)
            ->assertSee('stays')->assertDontSee('gone');

        $this->assertSame(['stays'], StoreEntry::query()->pluck('key')->all());
        $this->assertDatabaseHas('audit_logs', ['workspace_id' => $this->workspace->id, 'user_id' => $editor->id, 'action' => 'store.deleted', 'subject_label' => 'gone']);
    }

    public function test_clearing_deletes_only_what_matches_the_search_and_only_in_this_workspace(): void
    {
        $editor = $this->user(WorkspaceRole::Editor);
        StoreEntry::factory()->for($this->workspace)->create(['key' => 'order-1']);
        StoreEntry::factory()->for($this->workspace)->create(['key' => 'order-2']);
        StoreEntry::factory()->for($this->workspace)->create(['key' => 'invoice-1']);
        $elsewhere = StoreEntry::factory()->create(['key' => 'order-3']);

        $page = Livewire::actingAs($editor)->test('pages::workspaces.store', ['workspace' => $this->workspace])
            ->set('search', 'order')
            ->call('clear');

        $this->assertSame(['invoice-1', 'order-3'], StoreEntry::query()->orderBy('key')->pluck('key')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'store.cleared', 'user_id' => $editor->id, 'subject_label' => 'order']);

        $page->set('search', '')->call('clear');

        $this->assertSame([$elsewhere->id], StoreEntry::query()->pluck('id')->all());
    }

    public function test_a_viewer_cannot_delete_or_clear_values(): void
    {
        $entry = StoreEntry::factory()->for($this->workspace)->create();

        $viewer = $this->user(WorkspaceRole::Viewer);
        Livewire::actingAs($viewer)->test('pages::workspaces.store', ['workspace' => $this->workspace])->call('delete', $entry->id)->assertForbidden();
        Livewire::actingAs($viewer)->test('pages::workspaces.store', ['workspace' => $this->workspace])->call('clear')->assertForbidden();

        $this->assertSame(1, StoreEntry::count());
    }

    public function test_a_value_in_another_workspace_cannot_be_deleted_from_this_one(): void
    {
        $foreign = StoreEntry::factory()->create();

        Livewire::actingAs($this->user(WorkspaceRole::Editor))
            ->test('pages::workspaces.store', ['workspace' => $this->workspace])
            ->call('delete', $foreign->id)
            ->assertNotFound();

        $this->assertSame(1, StoreEntry::count());
    }
}
