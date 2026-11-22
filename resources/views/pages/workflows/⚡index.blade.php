<?php

use App\Engine\Editor\WorkflowExport;
use App\Engine\Graph\GraphNode;
use App\Engine\Graph\WorkflowGraph;
use App\Engine\NodeRegistry;
use App\Engine\Nodes\Triggers\ManualTrigger;
use App\Enums\NodeKind;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Services\Workflows;
use App\Support\Audit;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public Workspace $workspace;

    public string $name = '';

    #[Validate(['file', 'max:2048'])]
    public $import = null;

    #[Computed]
    public function workflows()
    {
        return $this->workspace->workflows()
            ->with(['publishedVersion', 'owner', 'triggers'])
            ->withCount('executions')
            ->orderBy('name')
            ->get();
    }

    public function triggerLabels(Workflow $workflow): string
    {
        $registry = app(NodeRegistry::class);

        return collect($workflow->draft()->nodes)
            ->filter(fn ($node) => $registry->has($node->type) && $registry->make($node->type)->kind() === NodeKind::Trigger)
            ->map(fn ($node) => $registry->make($node->type)->label())
            ->unique()
            ->join(', ');
    }

    #[Computed]
    public function canEdit(): bool
    {
        return Auth::user()->can('edit-workflows', $this->workspace);
    }

    public function create(Workflows $workflows): void
    {
        $this->authorize('edit-workflows', $this->workspace);
        $this->validate(['name' => ['required', 'string', 'max:255']]);

        $graph = WorkflowGraph::empty()->withNode(new GraphNode('manual', ManualTrigger::type(), __('workflows.index.run_manually'), position: ['x' => 80, 'y' => 160]));
        $workflow = $workflows->create($this->workspace, $this->name, Auth::user(), $graph);

        $this->redirectRoute('workspaces.workflows.edit', [$this->workspace, $workflow], navigate: true);
    }

    public function importFile(Workflows $workflows): void
    {
        $this->authorize('edit-workflows', $this->workspace);
        $this->validate(['import' => ['required', 'file', 'max:2048']]);

        $data = WorkflowExport::parse((string) file_get_contents($this->import->getRealPath()));

        $workflow = $workflows->create($this->workspace, $data['name'], Auth::user(), $data['graph']);
        $workflow->forceFill([
            'description' => $data['description'],
            'concurrency' => $data['concurrency'],
            'retention_days' => $data['retention_days'],
        ])->save();

        Audit::record('workflow.imported', $workflow, $this->workspace);

        $this->redirectRoute('workspaces.workflows.edit', [$this->workspace, $workflow], navigate: true);
    }

    public function delete(int $id, Workflows $workflows): void
    {
        $this->authorize('edit-workflows', $this->workspace);

        $workflows->delete($this->workspace->workflows()->findOrFail($id));
        Flux::toast(text: __('workflows.index.workflow_deleted'));
    }

    public function render()
    {
        return $this->view()->title(__('common.workflows').' · '.$this->workspace->name);
    }
}; ?>

<section class="w-full">
    <x-page-heading :heading="__('common.workflows')" :subheading="__('workflows.index.build_workflows_from_triggers_and')">
        @if ($this->canEdit)
            <x-slot:actions>
                <flux:modal.trigger name="import-workflow">
                    <flux:button icon="arrow-up-tray">{{ __('workflows.index.import') }}</flux:button>
                </flux:modal.trigger>
                <flux:modal.trigger name="new-workflow">
                    <flux:button variant="primary" icon="plus">{{ __('workflows.index.new_workflow') }}</flux:button>
                </flux:modal.trigger>
            </x-slot:actions>
        @endif
    </x-page-heading>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('common.name') }}</flux:table.column>
            <flux:table.column>{{ __('common.status') }}</flux:table.column>
            <flux:table.column>{{ __('common.triggers') }}</flux:table.column>
            <flux:table.column>{{ __('common.runs') }}</flux:table.column>
            <flux:table.column>{{ __('workflows.index.owner') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->workflows as $workflow)
                <flux:table.row :key="$workflow->id">
                    <flux:table.cell>
                        <flux:link :href="route('workspaces.workflows.edit', [$workspace, $workflow])" wire:navigate class="font-medium">{{ $workflow->name }}</flux:link>
                        @if ($workflow->description)
                            <div class="max-w-md truncate text-xs text-zinc-500">{{ $workflow->description }}</div>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        @if ($workflow->publishedVersion)
                            <flux:badge size="sm" color="green">{{ __('common.published_vn', ['n' => $workflow->publishedVersion->number]) }}</flux:badge>
                            @if ($workflow->hasUnpublishedChanges())
                                <flux:badge size="sm" color="amber">{{ __('workflows.index.draft_changes') }}</flux:badge>
                            @endif
                        @else
                            <flux:badge size="sm">{{ __('common.draft') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500">
                        {{ $this->triggerLabels($workflow) ?: '—' }}
                    </flux:table.cell>
                    <flux:table.cell>
                        @can('view-executions', $workspace)
                            <flux:link :href="route('workspaces.executions', [$workspace, 'workflow' => $workflow->id])" wire:navigate>{{ $workflow->executions_count }}</flux:link>
                        @else
                            {{ $workflow->executions_count }}
                        @endcan
                    </flux:table.cell>
                    <flux:table.cell>{{ $workflow->owner?->name }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:dropdown align="end">
                            <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('common.actions')" />
                            <flux:menu>
                                <flux:menu.item icon="pencil-square" :href="route('workspaces.workflows.edit', [$workspace, $workflow])" wire:navigate>{{ __('workflows.index.open_editor') }}</flux:menu.item>
                                <flux:menu.item icon="arrow-down-tray" :href="route('workspaces.workflows.export', [$workspace, $workflow])">{{ __('workflows.index.export_json') }}</flux:menu.item>
                                @if ($this->canEdit)
                                    <flux:menu.separator />
                                    <flux:menu.item variant="danger" icon="trash" wire:click="delete({{ $workflow->id }})"
                                        wire:confirm="{{ __('workflows.index.delete_name_with_its_versions', ['name' => $workflow->name]) }}">
                                        {{ __('common.delete') }}
                                    </flux:menu.item>
                                @endif
                            </flux:menu>
                        </flux:dropdown>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="py-10 text-center text-zinc-500">{{ __('workflows.index.no_workflows_yet') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="new-workflow" class="md:w-md">
        <form wire:submit="create" class="space-y-6">
            <flux:heading size="lg">{{ __('workflows.index.new_workflow') }}</flux:heading>
            <flux:input wire:model="name" :label="__('common.name')" required autofocus />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('common.cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('workflows.index.create_and_open') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="import-workflow" class="md:w-md">
        <form wire:submit="importFile" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('workflows.index.import_a_workflow') }}</flux:heading>
                <flux:text class="mt-1">{{ __('workflows.index.choose_a_workflow_json_file') }}</flux:text>
            </div>
            <flux:input type="file" wire:model="import" accept=".json,application/json" />
            <flux:error name="file" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('common.cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('workflows.index.import') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
