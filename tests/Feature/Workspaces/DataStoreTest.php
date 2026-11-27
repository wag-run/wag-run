<?php

namespace Tests\Feature\Workspaces;

use App\Enums\ValueType;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\StoreEntry;
use App\Models\StoreValue;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DataStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class DataStoreTest extends TestCase
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

    private function page(WorkspaceRole $role = WorkspaceRole::Editor): Testable
    {
        return Livewire::actingAs($this->user($role))->test('pages::workspaces.data-store', ['workspace' => $this->workspace]);
    }

    private function read(string $key, ?string $itemKey = null): mixed
    {
        $store = app(DataStore::class);

        return $store->read($store->entry($this->workspace, $key), $itemKey);
    }

    /**
     * @return list<array{string, array<string, mixed>|null}> each audit entry's action and metadata, oldest first
     */
    private function audited(): array
    {
        return AuditLog::query()->oldest('created_at')->orderBy('id')->get()->map(fn (AuditLog $log) => [$log->action, $log->metadata])->all();
    }

    public function test_a_viewer_sees_names_types_and_values_but_cannot_change_anything(): void
    {
        StoreEntry::factory()->for($this->workspace)->type(ValueType::Number)->holding(42)->create(['key' => 'threshold', 'description' => 'Alert above this']);
        StoreEntry::factory()->for($this->workspace)->type(ValueType::Boolean)->collection()->readOnly()->holding(['T-1' => true])->create(['key' => 'seen_tickets']);
        $other = StoreEntry::factory()->collection()->holding(['T-9' => 'elsewhere'])->create(['key' => 'other_workspace']);

        $this->actingAs($this->user(WorkspaceRole::Viewer))->get(route('workspaces.data-store', $this->workspace))
            ->assertOk()
            ->assertSeeInOrder(['seen_tickets', 'Boolean', 'Collection', '1 item', 'Read-only', 'threshold', 'Alert above this', 'Number', '42'])
            ->assertDontSee('other_workspace')
            ->assertDontSee('data-test="add-entry"', false)
            ->assertDontSee('data-test="edit-entry"', false)
            ->assertDontSee('data-test="read-only-toggle"', false);

        $threshold = $this->workspace->storeEntries()->where('key', 'threshold')->sole();
        $seen = $this->workspace->storeEntries()->where('key', 'seen_tickets')->sole();

        $this->page(WorkspaceRole::Viewer)->call('create')->assertForbidden();
        $this->page(WorkspaceRole::Viewer)->set('key', 'sneaky')->call('save')->assertForbidden();
        $this->page(WorkspaceRole::Viewer)->call('toggleReadOnly', $threshold->id)->assertForbidden();
        $this->page(WorkspaceRole::Viewer)->call('delete', $threshold->id)->assertForbidden();
        $this->page(WorkspaceRole::Viewer)->call('browse', $seen->id)->assertSee('T-1')->assertDontSee('data-test="item-form"', false)
            ->set('itemKey', 'T-2')->call('saveItem')->assertForbidden();
        $this->page(WorkspaceRole::Viewer)->call('browse', $seen->id)->call('deleteItem', $seen->values()->sole()->id)->assertForbidden();
        $this->page(WorkspaceRole::Viewer)->call('browse', $other->id)->assertNotFound();

        $this->assertSame(2, $this->workspace->storeEntries()->count());
        $this->assertSame(0, AuditLog::count());
    }

    public function test_an_editor_adds_a_typed_entry_with_its_current_value(): void
    {
        $this->page()
            ->call('create')
            ->set('key', 'threshold')
            ->set('type', 'number')
            ->set('value', '42')
            ->set('description', 'Alert above this')
            ->call('save')
            ->assertHasNoErrors();

        $entry = $this->workspace->storeEntries()->sole();
        $this->assertSame(['threshold', ValueType::Number, false, false, 'Alert above this'], [$entry->key, $entry->type, $entry->is_collection, $entry->read_only, $entry->description]);
        $this->assertSame(42, $this->read('threshold'));
        $this->assertSame([['data_store.entry_created', ['type' => 'number', 'collection' => false]]], $this->audited());
        $this->assertSame('threshold', AuditLog::sole()->subject_label);
    }

    public function test_an_entry_left_without_a_value_starts_as_its_types_empty_value(): void
    {
        foreach (['string' => '', 'number' => 0, 'boolean' => false, 'object' => [], 'array' => [], 'datetime' => null] as $type => $empty) {
            $this->page()->set('key', "empty_{$type}")->set('type', $type)->call('save')->assertHasNoErrors();

            $this->assertSame($empty, $this->read("empty_{$type}"));
        }

        $this->assertSame('{}', StoreValue::query()->whereRelation('entry', 'key', 'empty_object')->sole()->value);
    }

    public function test_a_collection_starts_with_no_items(): void
    {
        $this->page()->set('key', 'failures')->set('type', 'number')->set('holds', 'collection')->set('value', '5')->call('save')->assertHasNoErrors();

        $this->assertTrue($this->workspace->storeEntries()->sole()->is_collection);
        $this->assertSame([], $this->read('failures'));
    }

    public function test_a_name_must_be_usable_in_a_placeholder_and_unique_in_the_workspace(): void
    {
        StoreEntry::factory()->for($this->workspace)->create(['key' => 'taken']);
        StoreEntry::factory()->create(['key' => 'used_elsewhere']);

        $this->page()->set('key', 'has-dash')->call('save')->assertHasErrors(['key' => 'regex']);
        $this->page()->set('key', '9lives')->call('save')->assertHasErrors(['key' => 'regex']);
        $this->page()->set('key', 'taken')->call('save')->assertHasErrors(['key' => 'unique']);
        $this->page()->set('key', 'used_elsewhere')->call('save')->assertHasNoErrors();
    }

    public function test_a_value_that_does_not_fit_the_type_is_refused_and_nothing_is_saved(): void
    {
        $this->page()->set('key', 'threshold')->set('type', 'number')->set('value', 'lots')->call('save')
            ->assertHasErrors('value')
            ->assertSee('Expected Number, but got the text "lots".');

        $this->assertSame(0, StoreEntry::count());
    }

    public function test_editing_changes_the_value_description_and_read_only_but_not_the_name_type_or_kind(): void
    {
        $entry = StoreEntry::factory()->for($this->workspace)->type(ValueType::Object)->holding(['a' => 1])->create(['key' => 'settings']);

        $this->page()
            ->call('edit', $entry->id)
            ->assertSet('key', 'settings')
            ->assertSet('type', 'object')
            ->assertSet('value', "{\n    \"a\": 1\n}")
            ->set('key', 'renamed')
            ->set('type', 'string')
            ->set('holds', 'collection')
            ->set('value', '{"a": 2}')
            ->set('readOnly', true)
            ->call('save')
            ->assertHasNoErrors();

        $entry->refresh();
        $this->assertSame(['settings', ValueType::Object, false, true], [$entry->key, $entry->type, $entry->is_collection, $entry->read_only]);
        $this->assertSame(['a' => 2], $this->read('settings'));
        $this->assertSame([['data_store.entry_updated', ['changed' => ['value', 'read_only']]]], $this->audited());
    }

    public function test_people_can_still_edit_a_read_only_entry(): void
    {
        $entry = StoreEntry::factory()->for($this->workspace)->readOnly()->holding('https://old.example.com')->create(['key' => 'api_base_url']);

        $this->page()->call('edit', $entry->id)->set('value', 'https://new.example.com')->call('save')->assertHasNoErrors();

        $this->assertSame('https://new.example.com', $this->read('api_base_url'));
    }

    public function test_the_read_only_toggle_switches_an_entry_and_is_audited(): void
    {
        $entry = StoreEntry::factory()->for($this->workspace)->create(['key' => 'api_base_url']);

        $this->page()->call('toggleReadOnly', $entry->id);
        $this->assertTrue($entry->refresh()->read_only);

        $this->page()->call('toggleReadOnly', $entry->id);
        $this->assertFalse($entry->refresh()->read_only);

        $this->assertSame([
            ['data_store.entry_updated', ['changed' => ['read_only'], 'read_only' => true]],
            ['data_store.entry_updated', ['changed' => ['read_only'], 'read_only' => false]],
        ], $this->audited());
    }

    public function test_deleting_an_entry_deletes_its_values(): void
    {
        $entry = StoreEntry::factory()->for($this->workspace)->type(ValueType::Number)->collection()->holding(['a' => 1, 'b' => 2])->create(['key' => 'counts']);

        $this->page()->call('delete', $entry->id);

        $this->assertSame(0, StoreEntry::count());
        $this->assertSame(0, StoreValue::count());
        $this->assertSame([['data_store.entry_deleted', null]], $this->audited());
        $this->assertSame('counts', AuditLog::sole()->subject_label);
    }

    public function test_an_editor_adds_edits_and_deletes_a_collections_items(): void
    {
        $entry = StoreEntry::factory()->for($this->workspace)->type(ValueType::Number)->collection()->holding(['acme' => 1])->create(['key' => 'failures']);
        $page = $this->page()->call('browse', $entry->id)->assertSee('acme');

        $page->set('itemKey', 'globex')->set('itemValue', '3')->call('saveItem')->assertHasNoErrors()->assertSet('itemKey', '');
        $this->assertSame(['acme' => 1, 'globex' => 3], $this->read('failures'));

        $page->call('editItem', $entry->values()->where('item_key', 'acme')->sole()->id)
            ->assertSet('itemKey', 'acme')->assertSet('itemValue', '1')
            ->set('itemKey', 'changed')->set('itemValue', '10')->call('saveItem')->assertHasNoErrors();
        $this->assertSame(['acme' => 10, 'globex' => 3], $this->read('failures'));

        $page->set('itemKey', 'bad')->set('itemValue', 'many')->call('saveItem')->assertHasErrors('itemValue');
        $page->set('itemKey', ' ')->set('itemValue', '1')->call('saveItem')->assertHasErrors('itemKey');

        $page->call('deleteItem', $entry->values()->where('item_key', 'globex')->sole()->id);
        $this->assertSame(['acme' => 10], $this->read('failures'));

        $this->assertSame([
            ['data_store.item_saved', ['item' => 'globex']],
            ['data_store.item_saved', ['item' => 'acme']],
            ['data_store.item_deleted', ['item' => 'globex']],
        ], $this->audited());
    }

    public function test_searching_narrows_entries_and_items_by_key_and_treats_wildcards_as_text(): void
    {
        StoreEntry::factory()->for($this->workspace)->create(['key' => 'order_total']);
        StoreEntry::factory()->for($this->workspace)->create(['key' => 'orderXcount']);
        $items = StoreEntry::factory()->for($this->workspace)->collection()->holding(['a_1' => 'x', 'ab1' => 'y'])->create(['key' => 'invoices']);

        $this->page()
            ->set('search', 'order_')
            ->assertSee('order_total')->assertDontSee('orderXcount')->assertDontSee('invoices')
            ->set('search', '')
            ->call('browse', $items->id)
            ->set('itemSearch', 'a_')
            ->assertSee('a_1')->assertDontSee('ab1');
    }

    public function test_the_page_shows_how_many_values_the_workspace_holds_and_says_it_is_not_for_secrets(): void
    {
        config(['workflows.store.max_values' => 100000]);
        StoreEntry::factory()->for($this->workspace)->create();
        StoreEntry::factory()->for($this->workspace)->collection()->holding(['a' => 'x', 'b' => 'y'])->create();

        $this->page(WorkspaceRole::Viewer)
            ->assertSee('3 of 100,000 values used')
            ->assertSee('don\'t keep passwords or keys here');
    }

    public function test_a_value_added_here_counts_toward_the_workspaces_limit(): void
    {
        config(['workflows.store.max_values' => 1]);
        StoreEntry::factory()->for($this->workspace)->create(['key' => 'first']);

        $this->page()->set('key', 'second')->call('save')->assertHasErrors('value');

        $this->assertSame(['first'], $this->workspace->storeEntries()->pluck('key')->all());
    }

    public function test_the_sidebar_and_overview_link_to_the_data_store(): void
    {
        StoreEntry::factory()->for($this->workspace)->count(2)->create();

        $this->actingAs($this->user(WorkspaceRole::Viewer))->get(route('workspaces.show', $this->workspace))
            ->assertSee(route('workspaces.data-store', $this->workspace), false)
            ->assertSee('Data store');
        $this->actingAs($this->user(WorkspaceRole::Guest))->get(route('workspaces.show', $this->workspace))
            ->assertDontSee(route('workspaces.data-store', $this->workspace), false);
    }
}
