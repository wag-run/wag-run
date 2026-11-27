<?php

use App\Enums\ValueType;
use App\Models\StoreEntry;
use App\Models\StoreValue;
use App\Models\Workspace;
use App\Services\DataStore;
use App\Support\Audit;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public Workspace $workspace;

    #[Url(as: 'q')]
    public string $search = '';

    #[Locked]
    public ?int $editingId = null;

    public string $key = '';

    public string $type = 'string';

    /**
     * Whether the entry holds one value ("single") or a collection.
     */
    public string $holds = 'single';

    public string $value = '';

    public bool $readOnly = false;

    public string $description = '';

    /**
     * The collection whose items are open.
     */
    #[Locked]
    public ?int $browsingId = null;

    public string $itemSearch = '';

    /**
     * The item being edited, or null while adding one.
     */
    #[Locked]
    public ?int $editingItemId = null;

    public string $itemKey = '';

    public string $itemValue = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedItemSearch(): void
    {
        $this->resetPage('items');
    }

    /**
     * @return LengthAwarePaginator<int, StoreEntry>
     */
    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        return $this->workspace->storeEntries()
            ->when(trim($this->search) !== '', fn (Builder $query) => $this->whereContains($query, 'key', $this->search))
            ->with(['values' => fn ($query) => $query->where('item_key', '')])
            ->withCount('values')
            ->orderBy('key')
            ->paginate(25);
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->can('manage-data-store', $this->workspace);
    }

    #[Computed]
    public function valueCount(): int
    {
        return app(DataStore::class)->count($this->workspace);
    }

    #[Computed]
    public function browsing(): ?StoreEntry
    {
        return $this->browsingId ? $this->workspace->storeEntries()->where('is_collection', true)->find($this->browsingId) : null;
    }

    /**
     * @return LengthAwarePaginator<int, StoreValue>|null
     */
    #[Computed]
    public function items(): ?LengthAwarePaginator
    {
        return $this->browsing?->values()
            ->when(trim($this->itemSearch) !== '', fn (Builder $query) => $this->whereContains($query, 'item_key', $this->itemSearch))
            ->orderBy('item_key')
            ->paginate(10, pageName: 'items');
    }

    /**
     * The type of the entry in the form: the saved one while editing.
     */
    public function formType(): ValueType
    {
        return ValueType::tryFrom($this->type) ?? ValueType::String;
    }

    public function create(): void
    {
        $this->authorize('manage-data-store', $this->workspace);
        $this->resetForm();
        Flux::modal('entry-form')->show();
    }

    public function edit(int $id): void
    {
        $this->authorize('manage-data-store', $this->workspace);

        $entry = $this->workspace->storeEntries()->findOrFail($id);

        $this->resetForm();
        $this->editingId = $entry->id;
        $this->key = $entry->key;
        $this->type = $entry->type->value;
        $this->holds = $entry->is_collection ? 'collection' : 'single';
        $this->readOnly = $entry->read_only;
        $this->description = (string) $entry->description;
        $this->value = $entry->is_collection ? '' : $this->toInput($entry->type, app(DataStore::class)->read($entry));

        Flux::modal('entry-form')->show();
    }

    public function save(DataStore $store): void
    {
        $this->authorize('manage-data-store', $this->workspace);

        $entry = $this->editingId ? $this->workspace->storeEntries()->findOrFail($this->editingId) : null;

        $validated = $this->validate([
            'key' => $entry ? [] : ['required', 'string', 'max:'.DataStore::KEY_MAX_LENGTH, 'regex:'.DataStore::KEY_PATTERN,
                Rule::unique('store_entries', 'key')->where('workspace_id', $this->workspace->id)],
            'type' => $entry ? [] : ['required', Rule::enum(ValueType::class)],
            'description' => ['nullable', 'string', 'max:255'],
        ], ['key.regex' => __('common.use_letters_numbers_and_underscores')]);

        try {
            if ($entry) {
                $changed = array_keys(array_filter([
                    'value' => ! $entry->is_collection && $this->value !== $this->toInput($entry->type, $store->read($entry)),
                    'read_only' => $entry->read_only !== $this->readOnly,
                    'description' => (string) $entry->description !== trim($this->description),
                ]));

                if (in_array('value', $changed, true)) {
                    $store->put($entry, $this->fromInput($entry->type, $this->value));
                }

                $entry->update(['read_only' => $this->readOnly, 'description' => trim($this->description) ?: null]);

                if ($changed !== []) {
                    Audit::record('data_store.entry_updated', $entry, $this->workspace, ['changed' => $changed]);
                }
            } else {
                $type = ValueType::from($validated['type']);
                $isCollection = $this->holds === 'collection';
                $entry = $store->create($this->workspace, $validated['key'], $type, $isCollection, $isCollection ? null : $this->fromInput($type, $this->value), $this->readOnly, trim($this->description) ?: null);

                Audit::record('data_store.entry_created', $entry, $this->workspace, ['type' => $type->value, 'collection' => $entry->is_collection]);
            }
        } catch (RuntimeException $e) {
            $this->addError('value', $e->getMessage());

            return;
        }

        Flux::modal('entry-form')->close();
        Flux::toast(variant: 'success', text: __('workspaces.data_store.entry_saved'));
        $this->resetForm();
        unset($this->entries, $this->valueCount);
    }

    public function toggleReadOnly(int $id): void
    {
        $this->authorize('manage-data-store', $this->workspace);

        $entry = $this->workspace->storeEntries()->findOrFail($id);
        $entry->update(['read_only' => ! $entry->read_only]);

        Audit::record('data_store.entry_updated', $entry, $this->workspace, ['changed' => ['read_only'], 'read_only' => $entry->read_only]);
        unset($this->entries);
    }

    public function delete(int $id): void
    {
        $this->authorize('manage-data-store', $this->workspace);

        $entry = $this->workspace->storeEntries()->findOrFail($id);
        $entry->delete();

        Audit::record('data_store.entry_deleted', $entry, $this->workspace);
        Flux::toast(text: __('workspaces.data_store.entry_deleted'));
        unset($this->entries, $this->valueCount);
    }

    public function browse(int $id): void
    {
        $entry = $this->workspace->storeEntries()->where('is_collection', true)->findOrFail($id);

        $this->browsingId = $entry->id;
        $this->itemSearch = '';
        $this->resetItemForm();
        $this->resetPage('items');
        unset($this->browsing, $this->items);

        Flux::modal('entry-items')->show();
    }

    public function editItem(int $id): void
    {
        $this->authorize('manage-data-store', $this->workspace);

        $item = $this->browsing?->values()->findOrFail($id);

        $this->resetItemForm();
        $this->editingItemId = $item->id;
        $this->itemKey = $item->item_key;
        $this->itemValue = $this->toInput($this->browsing->type, $item->decoded());
    }

    public function saveItem(DataStore $store): void
    {
        $this->authorize('manage-data-store', $this->workspace);

        $entry = $this->browsing ?? abort(404);
        $itemKey = $this->editingItemId ? $entry->values()->findOrFail($this->editingItemId)->item_key : $this->itemKey;

        try {
            $store->put($entry, $this->fromInput($entry->type, $this->itemValue), $itemKey);
        } catch (RuntimeException $e) {
            $this->addError(trim((string) $itemKey) === '' ? 'itemKey' : 'itemValue', $e->getMessage());

            return;
        }

        Audit::record('data_store.item_saved', $entry, $this->workspace, ['item' => DataStore::cleanItemKey($itemKey)]);
        Flux::toast(variant: 'success', text: __('workspaces.data_store.item_saved'));
        $this->resetItemForm();
        unset($this->items, $this->entries, $this->valueCount);
    }

    public function cancelItem(): void
    {
        $this->resetItemForm();
    }

    public function deleteItem(int $id): void
    {
        $this->authorize('manage-data-store', $this->workspace);

        $entry = $this->browsing ?? abort(404);
        $item = $entry->values()->findOrFail($id);
        $item->delete();

        Audit::record('data_store.item_deleted', $entry, $this->workspace, ['item' => $item->item_key]);
        Flux::toast(text: __('workspaces.data_store.item_deleted'));
        $this->resetItemForm();
        unset($this->items, $this->entries, $this->valueCount);
    }

    /**
     * A value as the form edits it: text as it is, anything else as JSON.
     */
    public function toInput(ValueType $type, mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_string($value) => $value,
            $type === ValueType::Object && $value === [] => '{}',
            default => (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
        };
    }

    /**
     * What the form's text stands for. Empty means the type's empty value,
     * except for a String, where it is the value.
     */
    protected function fromInput(ValueType $type, string $input): ?string
    {
        return $type === ValueType::String || trim($input) !== '' ? $input : null;
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    protected function whereContains(Builder $query, string $column, string $search): void
    {
        $query->whereRaw(
            $query->getQuery()->getGrammar()->wrap($column)." like ? escape '!'",
            ['%'.preg_replace('/[!%_]/', '!$0', trim($search)).'%'],
        );
    }

    protected function resetForm(): void
    {
        $this->reset('editingId', 'key', 'type', 'holds', 'value', 'readOnly', 'description');
        $this->resetValidation();
    }

    protected function resetItemForm(): void
    {
        $this->reset('editingItemId', 'itemKey', 'itemValue');
        $this->resetValidation();
    }

    public function render()
    {
        return $this->view()->title(__('common.data_store').' · '.$this->workspace->name);
    }
}; ?>

<section class="w-full">
    <x-page-heading :heading="__('common.data_store')" :subheading="__('workspaces.data_store.subheading')">
        @if ($this->canManage)
            <x-slot:actions>
                <flux:button variant="primary" icon="plus" wire:click="create" data-test="add-entry">{{ __('workspaces.data_store.add_entry') }}</flux:button>
            </x-slot:actions>
        @endif
    </x-page-heading>

    <flux:text class="mb-4 text-xs">{{ __('workspaces.data_store.not_for_secrets') }}</flux:text>

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('workspaces.data_store.search_entries')" clearable class="max-w-64" data-test="search-entries" />
        <flux:text class="text-xs" data-test="value-count">{{ __('workspaces.data_store.values_used', ['count' => number_format($this->valueCount), 'max' => number_format((int) config('workflows.store.max_values'))]) }}</flux:text>
    </div>

    <flux:table :paginate="$this->entries">
        <flux:table.columns>
            <flux:table.column>{{ __('common.name') }}</flux:table.column>
            <flux:table.column>{{ __('common.type') }}</flux:table.column>
            <flux:table.column>{{ __('common.value') }}</flux:table.column>
            <flux:table.column>{{ __('workspaces.data_store.read_only') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->entries as $entry)
                @php
                    $current = $entry->is_collection ? null : $this->toInput($entry->type, $entry->values->first()?->decoded());
                @endphp
                <flux:table.row :key="$entry->id">
                    <flux:table.cell>
                        <div class="font-mono">{{ $entry->key }}</div>
                        @if ($entry->description)
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $entry->description }}</div>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        {{ $entry->type->label() }}
                        @if ($entry->is_collection)
                            <flux:badge size="sm" color="zinc" class="ms-1">{{ __('workspaces.data_store.collection') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    @if ($entry->is_collection)
                        <flux:table.cell>
                            <flux:button size="sm" variant="ghost" icon="list-bullet" wire:click="browse({{ $entry->id }})" data-test="browse-items">{{ trans_choice('workspaces.data_store.item_count', $entry->values_count, ['count' => number_format($entry->values_count)]) }}</flux:button>
                        </flux:table.cell>
                    @else
                        <flux:table.cell class="max-w-xs truncate font-mono" :title="Str::limit($current, 1000)">{{ Str::limit($current, 120) }}</flux:table.cell>
                    @endif
                    <flux:table.cell>
                        @if ($this->canManage)
                            <flux:switch :checked="$entry->read_only" wire:click="toggleReadOnly({{ $entry->id }})" :aria-label="__('workspaces.data_store.read_only')" data-test="read-only-toggle" />
                        @elseif ($entry->read_only)
                            <flux:badge size="sm" color="zinc">{{ __('workspaces.data_store.read_only') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        @if ($this->canManage)
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $entry->id }})" :aria-label="__('common.edit')" data-test="edit-entry" />
                            <flux:button size="sm" variant="ghost" icon="trash" data-test="delete-entry" x-on:click="$confirm({{ \Illuminate\Support\Js::from(['title' => __('workspaces.data_store.delete_entry'), 'message' => __('workspaces.data_store.delete_entry_message', ['key' => $entry->key]), 'confirm' => __('common.delete'), 'danger' => true]) }}).then((ok) => ok && $wire.delete({{ $entry->id }}))" :aria-label="__('common.delete')" />
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="text-center text-zinc-500">{{ trim($search) === '' ? __('workspaces.data_store.no_entries_yet') : __('workspaces.data_store.no_matching_entries') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="entry-form" class="md:w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('workspaces.data_store.edit_entry') : __('workspaces.data_store.add_entry') }}</flux:heading>

            <flux:input wire:model="key" :label="__('common.name')" class="font-mono" placeholder="api_base_url" :disabled="(bool) $editingId" :description="$editingId ? __('workspaces.data_store.fixed_once_created') : __('workspaces.data_store.name_help')" required />

            <div class="grid grid-cols-2 gap-3">
                <flux:select wire:model.live="type" :label="__('common.type')" :disabled="(bool) $editingId">
                    @foreach (ValueType::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="holds" :label="__('workspaces.data_store.holds')" :disabled="(bool) $editingId">
                    <flux:select.option value="single">{{ __('workspaces.data_store.a_single_value') }}</flux:select.option>
                    <flux:select.option value="collection">{{ __('workspaces.data_store.a_collection') }}</flux:select.option>
                </flux:select>
            </div>

            @if ($holds === 'collection')
                <flux:text class="text-xs">{{ __('workspaces.data_store.collection_help') }}</flux:text>
            @else
                @include('partials.store-value-input', ['type' => $this->formType(), 'model' => 'value', 'label' => __('workspaces.data_store.current_value')])
            @endif

            <flux:input wire:model="description" :label="__('common.description')" />

            <flux:switch wire:model="readOnly" :label="__('workspaces.data_store.read_only')" :description="__('workspaces.data_store.read_only_help')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('common.cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('common.save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="entry-items" class="md:w-xl">
        @if ($this->browsing)
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg" class="font-mono">{{ $this->browsing->key }}</flux:heading>
                    <flux:text class="text-xs">{{ __('workspaces.data_store.items_help', ['type' => $this->browsing->type->label(), 'key' => $this->browsing->key]) }}</flux:text>
                </div>

                <flux:input wire:model.live.debounce.300ms="itemSearch" icon="magnifying-glass" :placeholder="__('workspaces.data_store.search_items')" clearable data-test="search-items" />

                <flux:table :paginate="$this->items">
                    <flux:table.columns>
                        <flux:table.column>{{ __('workspaces.data_store.item_key') }}</flux:table.column>
                        <flux:table.column>{{ __('common.value') }}</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($this->items as $item)
                            @php
                                $shown = $this->toInput($this->browsing->type, $item->decoded());
                            @endphp
                            <flux:table.row :key="$item->id">
                                <flux:table.cell class="max-w-40 truncate font-mono" :title="$item->item_key">{{ $item->item_key }}</flux:table.cell>
                                <flux:table.cell class="max-w-48 truncate font-mono" :title="Str::limit($shown, 1000)">{{ Str::limit($shown, 80) }}</flux:table.cell>
                                <flux:table.cell align="end">
                                    @if ($this->canManage)
                                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editItem({{ $item->id }})" :aria-label="__('common.edit')" data-test="edit-item" />
                                        <flux:button size="sm" variant="ghost" icon="trash" data-test="delete-item" x-on:click="$confirm({{ \Illuminate\Support\Js::from(['title' => __('workspaces.data_store.delete_item'), 'message' => __('workspaces.data_store.delete_item_message', ['key' => $item->item_key]), 'confirm' => __('common.delete'), 'danger' => true]) }}).then((ok) => ok && $wire.deleteItem({{ $item->id }}))" :aria-label="__('common.delete')" />
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="3" class="text-center text-zinc-500">{{ trim($itemSearch) === '' ? __('workspaces.data_store.no_items_yet') : __('workspaces.data_store.no_matching_items') }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>

                @if ($this->canManage)
                    <flux:separator />
                    <form wire:submit="saveItem" class="space-y-4" data-test="item-form">
                        <flux:heading>{{ $editingItemId ? __('workspaces.data_store.edit_item') : __('workspaces.data_store.add_item') }}</flux:heading>
                        <flux:input wire:model="itemKey" :label="__('workspaces.data_store.item_key')" class="font-mono" :disabled="(bool) $editingItemId" maxlength="191" />
                        @include('partials.store-value-input', ['type' => $this->browsing->type, 'model' => 'itemValue', 'label' => __('common.value')])
                        <div class="flex justify-end gap-2">
                            @if ($editingItemId)
                                <flux:button size="sm" variant="ghost" wire:click="cancelItem">{{ __('common.cancel') }}</flux:button>
                            @endif
                            <flux:button size="sm" type="submit" variant="primary">{{ $editingItemId ? __('common.save') : __('workspaces.data_store.add_item') }}</flux:button>
                        </div>
                    </form>
                @endif
            </div>
        @endif
    </flux:modal>
</section>
