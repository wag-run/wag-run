<?php

use App\Enums\ExecutionStatus;
use App\Models\Execution;
use App\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public Workspace $workspace;

    #[Url]
    public ?int $workflow = null;

    #[Url]
    public string $status = '';

    public function updatedWorkflow(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Execution>
     */
    #[Computed]
    public function executions(): LengthAwarePaginator
    {
        return $this->workspace->executions()
            ->with(['workflow', 'version', 'triggeredBy'])
            ->when($this->workflow, fn ($query) => $query->where('workflow_id', $this->workflow))
            ->when(ExecutionStatus::tryFrom($this->status), fn ($query, ExecutionStatus $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate(25);
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function workflows(): array
    {
        return $this->workspace->workflows()->orderBy('name')->pluck('name', 'id')->all();
    }

    #[Computed]
    public function hasRunsInProgress(): bool
    {
        return $this->executions->getCollection()->contains(fn (Execution $execution) => $execution->isInProgress());
    }

    public function render()
    {
        return $this->view()->title(__('runs.index.title'));
    }
}; ?>

<section class="w-full" @if ($this->hasRunsInProgress) wire:poll.5s @endif>
    <x-page-heading :heading="__('common.runs')" :subheading="__('runs.index.every_run_of_this_workspace')" />

    <div class="mb-4 flex flex-wrap gap-3">
        <flux:select wire:model.live="workflow" class="max-w-64" data-test="workflow-filter">
            <flux:select.option value="">{{ __('common.all_workflows') }}</flux:select.option>
            @foreach ($this->workflows as $id => $name)
                <flux:select.option :value="$id">{{ $name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="status" class="max-w-48">
            <flux:select.option value="">{{ __('runs.index.any_status') }}</flux:select.option>
            @foreach (ExecutionStatus::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:table :paginate="$this->executions">
        <flux:table.columns>
            <flux:table.column>{{ __('common.run') }}</flux:table.column>
            <flux:table.column>{{ __('common.workflow') }}</flux:table.column>
            <flux:table.column>{{ __('common.status') }}</flux:table.column>
            <flux:table.column>{{ __('runs.index.trigger') }}</flux:table.column>
            <flux:table.column>{{ __('runs.index.started') }}</flux:table.column>
            <flux:table.column>{{ __('runs.index.duration') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->executions as $execution)
                <flux:table.row :key="$execution->id">
                    <flux:table.cell>
                        <flux:link :href="route('workspaces.executions.show', [$workspace, $execution])" wire:navigate data-test="run-link">#{{ $execution->id }}</flux:link>
                    </flux:table.cell>
                    <flux:table.cell>
                        {{ $execution->workflow->name }}
                        <span class="text-xs text-zinc-500">{{ __('common.version_short', ['n' => $execution->version->number]) }}</span>
                    </flux:table.cell>
                    <flux:table.cell><flux:badge size="sm" :color="$execution->status->color()">{{ $execution->status->label() }}</flux:badge></flux:table.cell>
                    <flux:table.cell>
                        {{ __('runs.trigger_types.'.$execution->trigger_type) }}
                        @if ($execution->triggeredBy) <span class="text-xs text-zinc-500">· {{ $execution->triggeredBy->name }}</span> @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $execution->started_at?->diffForHumans() }}</flux:table.cell>
                    <flux:table.cell>{{ $execution->durationForHumans() }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center text-zinc-500">{{ __('runs.index.no_runs_yet') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
