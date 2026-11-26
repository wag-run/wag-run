<?php

use App\Models\StoreEntry;
use App\Models\Workspace;
use App\Support\Audit;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public Workspace $workspace;

    #[Url(as: 'q')]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return Builder<StoreEntry>
     */
    protected function entries(): Builder
    {
        return StoreEntry::query()->where('workspace_id', $this->workspace->id)->live()
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->whereRaw(
                $query->getQuery()->getGrammar()->wrap('key')." like ? escape '!'",
                ['%'.preg_replace('/[!%_]/', '!$0', trim($this->search)).'%'],
            ));
    }

    /**
     * @return LengthAwarePaginator<int, StoreEntry>
     */
    #[Computed]
    public function values(): LengthAwarePaginator
    {
        return $this->entries()->orderBy('key')->paginate(25);
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function workflowNames(): array
    {
        return $this->workspace->workflows()->whereIn('id', $this->values->pluck('namespace')->filter()->all())->pluck('name', 'id')->all();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->can('manage-store', $this->workspace);
    }

    public function delete(int $id): void
    {
        $this->authorize('manage-store', $this->workspace);

        $entry = $this->workspace->storeEntries()->findOrFail($id);
        $entry->delete();

        Audit::record('store.deleted', $entry, $this->workspace, label: $entry->key);
        Flux::toast(text: __('workspaces.store.value_deleted'));
    }

    /**
     * Delete every value whose key matches the search, or all of them.
     */
    public function clear(): void
    {
        $this->authorize('manage-store', $this->workspace);

        $count = $this->entries()->delete();

        Audit::record('store.cleared', null, $this->workspace, ['count' => $count, 'search' => trim($this->search) ?: null], label: trim($this->search) ?: null);
        Flux::toast(text: __('workspaces.store.cleared', ['count' => number_format($count)]));
        $this->resetPage();
    }

    public function render()
    {
        return $this->view()->title(__('common.store').' · '.$this->workspace->name);
    }
}; ?>

<section class="w-full">
    <x-page-heading :heading="__('common.store')" :subheading="__('workspaces.store.subheading')">
        @if ($this->canManage && $this->values->total() > 0)
            <x-slot:actions>
                <flux:button variant="danger" icon="trash" data-test="clear-values"
                    x-on:click="$confirm({{ \Illuminate\Support\Js::from([
                        'title' => trim($search) === '' ? __('workspaces.store.clear_all') : __('workspaces.store.clear_matching'),
                        'message' => trim($search) === ''
                            ? __('workspaces.store.clear_message', ['count' => number_format($this->values->total())])
                            : __('workspaces.store.clear_matching_message', ['count' => number_format($this->values->total()), 'search' => trim($search)]),
                        'confirm' => __('workspaces.store.clear'),
                        'danger' => true,
                    ]) }}).then((ok) => ok && $wire.clear())">
                    {{ trim($search) === '' ? __('workspaces.store.clear_all') : __('workspaces.store.clear_matching') }}
                </flux:button>
            </x-slot:actions>
        @endif
    </x-page-heading>

    <div class="mb-4 flex flex-wrap gap-3">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('workspaces.store.search_keys')" clearable class="max-w-64" data-test="search-keys" />
    </div>

    <flux:table :paginate="$this->values">
        <flux:table.columns>
            <flux:table.column>{{ __('common.key') }}</flux:table.column>
            <flux:table.column>{{ __('common.value') }}</flux:table.column>
            <flux:table.column>{{ __('workspaces.store.shared_with') }}</flux:table.column>
            <flux:table.column>{{ __('workspaces.store.expires') }}</flux:table.column>
            <flux:table.column>{{ __('workspaces.store.updated') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->values as $entry)
                <flux:table.row :key="$entry->id">
                    <flux:table.cell class="font-mono">{{ $entry->key }}</flux:table.cell>
                    <flux:table.cell class="max-w-xs truncate font-mono" :title="Str::limit($entry->value, 1000)">{{ Str::limit($entry->value, 120) }}</flux:table.cell>
                    <flux:table.cell>{{ $entry->namespace === '' ? __('workspaces.store.the_workspace') : ($this->workflowNames[$entry->namespace] ?? __('workspaces.store.deleted_workflow')) }}</flux:table.cell>
                    <flux:table.cell>{{ $entry->expires_at?->diffForHumans() ?? __('workspaces.store.never') }}</flux:table.cell>
                    <flux:table.cell>{{ $entry->updated_at->diffForHumans() }}</flux:table.cell>
                    <flux:table.cell align="end">
                        @if ($this->canManage)
                            <flux:button size="sm" variant="ghost" icon="trash" data-test="delete-value" x-on:click="$confirm({{ \Illuminate\Support\Js::from(['title' => __('workspaces.store.delete_value'), 'message' => __('workspaces.store.delete_message'), 'confirm' => __('common.delete')]) }}).then((ok) => ok && $wire.delete({{ $entry->id }}))" :aria-label="__('common.delete')" />
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center text-zinc-500">{{ trim($search) === '' ? __('workspaces.store.no_values_yet') : __('workspaces.store.no_matching_values') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
